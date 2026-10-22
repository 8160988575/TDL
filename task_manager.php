<?php
/**
 * THE DIVINE LANDS - Task Management
 * Procedural Core PHP + MySQLi. Designed to be added as a separate page.
 * Change DB credentials below to match your existing dbcon.php if needed.
 */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
mysqli_report(MYSQLI_REPORT_OFF);

// Match the connection convention used in the existing TDL project.
$dbHost = 'localhost';
$dbUser = 'root';
$dbPass = '';
$dbName = 'tdl';
$con = mysqli_connect($dbHost, $dbUser, $dbPass, $dbName);
if (!$con) { http_response_code(500); exit('Database connection failed. Check the database settings at the top of task_manager.php.'); }
mysqli_set_charset($con, 'utf8mb4');

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function redirectTasks($query = '') { $page = basename($_SERVER['PHP_SELF'] ?? 'task_manager.php'); header('Location: ' . $page . ($query !== '' ? '?' . $query : '')); exit; }
function taskStmt($con, $sql, $types = '', $params = []) {
    $stmt = mysqli_prepare($con, $sql);
    if (!$stmt) throw new RuntimeException('Database query could not be prepared.');
    if ($types !== '') {
        $refs = [$types];
        foreach ($params as $k => &$value) $refs[] = &$value;
        if (!call_user_func_array([$stmt, 'bind_param'], $refs)) throw new RuntimeException('Could not bind query parameters.');
    }
    if (!mysqli_stmt_execute($stmt)) throw new RuntimeException('Database operation failed.');
    return $stmt;
}
function taskFlash($type, $message) { $_SESSION['task_flash'] = ['type'=>$type, 'message'=>$message]; }

if (empty($_SESSION['task_csrf'])) $_SESSION['task_csrf'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['task_csrf'];
$selfPage = basename($_SERVER['PHP_SELF'] ?? 'task_manager.php');
$statuses = ['To Do', 'In Progress', 'On Hold', 'Blocked', 'In Review', 'Completed', 'Cancelled'];
$priorities = ['Urgent', 'High', 'Medium', 'Low'];
$grades = ['A', 'B', 'C', 'D'];
$categories = ['General', 'Client Follow-up', 'Sales', 'Land / Property', 'Documentation', 'Finance', 'Marketing', 'Internal'];

// Ensure module tables exist. These are new task-specific tables; existing tables are not modified.
$schema = [
"CREATE TABLE IF NOT EXISTS tasks (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 title VARCHAR(200) NOT NULL,
 description TEXT NULL,
 category VARCHAR(80) NOT NULL DEFAULT 'General',
 priority ENUM('Urgent','High','Medium','Low') NOT NULL DEFAULT 'Medium',
 grade ENUM('A','B','C','D') NOT NULL DEFAULT 'B',
 status ENUM('To Do','In Progress','On Hold','Blocked','In Review','Completed','Cancelled') NOT NULL DEFAULT 'To Do',
 progress TINYINT UNSIGNED NOT NULL DEFAULT 0,
 assignee VARCHAR(150) NULL,
 created_by VARCHAR(150) NULL,
 start_date DATE NULL,
 due_date DATE NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX idx_task_status (status), INDEX idx_task_priority (priority), INDEX idx_task_grade (grade), INDEX idx_task_due (due_date), INDEX idx_task_assignee (assignee)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS task_notes (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 task_id BIGINT UNSIGNED NOT NULL,
 note TEXT NOT NULL,
 created_by VARCHAR(150) NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_task_notes_task (task_id),
 CONSTRAINT fk_task_notes_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS daily_task_templates (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 title VARCHAR(200) NOT NULL,
 description TEXT NULL,
 recurrence ENUM('Daily','Weekdays','Selected days') NOT NULL DEFAULT 'Daily',
 days_of_week VARCHAR(30) NULL,
 priority ENUM('Urgent','High','Medium','Low') NOT NULL DEFAULT 'Medium',
 grade ENUM('A','B','C','D') NOT NULL DEFAULT 'B',
 assignee VARCHAR(150) NULL,
 target_time TIME NULL,
 is_active TINYINT(1) NOT NULL DEFAULT 1,
 created_by VARCHAR(150) NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX idx_daily_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS daily_task_logs (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 template_id BIGINT UNSIGNED NOT NULL,
 task_date DATE NOT NULL,
 status ENUM('Pending','Completed','Skipped') NOT NULL DEFAULT 'Pending',
 progress TINYINT UNSIGNED NOT NULL DEFAULT 0,
 note TEXT NULL,
 completed_at DATETIME NULL,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_daily_task_date (template_id, task_date),
 INDEX idx_daily_log_date (task_date),
 CONSTRAINT fk_daily_log_template FOREIGN KEY (template_id) REFERENCES daily_task_templates(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
];
foreach ($schema as $sql) { if (!mysqli_query($con, $sql)) { http_response_code(500); exit('Could not initialize task tables. Check MySQL permissions and run task_schema.sql.'); } }
// Add progress to existing installations without deleting daily-task history.
$dailyProgressColumn = mysqli_query($con, "SHOW COLUMNS FROM daily_task_logs LIKE 'progress'");
if ($dailyProgressColumn && mysqli_num_rows($dailyProgressColumn) === 0) { mysqli_query($con, "ALTER TABLE daily_task_logs ADD COLUMN progress TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER status"); }
if ($dailyProgressColumn) mysqli_free_result($dailyProgressColumn);

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) throw new RuntimeException('Security token expired. Refresh the page and try again.');
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'save_task') {
            $id = (int)($_POST['id'] ?? 0);
            $title = trim((string)($_POST['title'] ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));
            $category = trim((string)($_POST['category'] ?? 'General'));
            $priority = (string)($_POST['priority'] ?? 'Medium');
            $grade = (string)($_POST['grade'] ?? 'B');
            $status = (string)($_POST['status'] ?? 'To Do');
            $progress = max(0, min(100, (int)($_POST['progress'] ?? 0)));
            $assignee = trim((string)($_POST['assignee'] ?? ''));
            $createdBy = trim((string)($_POST['created_by'] ?? ''));
            $startDate = trim((string)($_POST['start_date'] ?? ''));
            $dueDate = trim((string)($_POST['due_date'] ?? ''));
            if ($title === '') throw new RuntimeException('Task title is required.');
            if (mb_strlen($title) > 200) throw new RuntimeException('Task title must be 200 characters or fewer.');
            if (!in_array($priority, $priorities, true)) $priority = 'Medium';
            if (!in_array($grade, $grades, true)) $grade = 'B';
            if (!in_array($status, $statuses, true)) $status = 'To Do';
            if (!in_array($category, $categories, true)) $category = 'General';
            foreach (['start date'=>$startDate, 'due date'=>$dueDate] as $label=>$date) {
                if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) throw new RuntimeException('Invalid ' . $label . '.');
            }
            if ($status === 'Completed') $progress = 100;
            elseif ($progress === 100 && $status !== 'Completed') $status = 'In Review';
            $startDate = $startDate === '' ? null : $startDate;
            $dueDate = $dueDate === '' ? null : $dueDate;
            $assignee = $assignee === '' ? null : $assignee;
            $createdBy = $createdBy === '' ? null : $createdBy;
            if ($id > 0) {
                $stmt = taskStmt($con, 'UPDATE tasks SET title=?, description=?, category=?, priority=?, grade=?, status=?, progress=?, assignee=?, created_by=?, start_date=?, due_date=? WHERE id=?', 'ssssssissssi', [$title,$description,$category,$priority,$grade,$status,$progress,$assignee,$createdBy,$startDate,$dueDate,$id]);
                mysqli_stmt_close($stmt); taskFlash('success', 'Task updated successfully.');
            } else {
                $stmt = taskStmt($con, 'INSERT INTO tasks (title,description,category,priority,grade,status,progress,assignee,created_by,start_date,due_date) VALUES (?,?,?,?,?,?,?,?,?,?,?)', 'ssssssissss', [$title,$description,$category,$priority,$grade,$status,$progress,$assignee,$createdBy,$startDate,$dueDate]);
                mysqli_stmt_close($stmt); taskFlash('success', 'Task created successfully.');
            }
            redirectTasks();
        }
        if ($action === 'quick_update') {
            $id = (int)($_POST['id'] ?? 0); $status = (string)($_POST['status'] ?? 'To Do'); $progress = max(0,min(100,(int)($_POST['progress'] ?? 0)));
            if ($id < 1 || !in_array($status, $statuses, true)) throw new RuntimeException('Invalid task update.');
            if ($status === 'Completed') $progress = 100;
            elseif ($progress === 100) $status = 'In Review';
            $stmt = taskStmt($con, 'UPDATE tasks SET status=?, progress=? WHERE id=?', 'sii', [$status,$progress,$id]); mysqli_stmt_close($stmt);
            taskFlash('success','Task progress updated.'); redirectTasks($_GET ? http_build_query($_GET) : '');
        }
        if ($action === 'add_note') {
            $id = (int)($_POST['task_id'] ?? 0); $note = trim((string)($_POST['note'] ?? '')); $by = trim((string)($_POST['created_by'] ?? ''));
            if ($id < 1 || $note === '') throw new RuntimeException('Enter a note before saving.');
            $check = taskStmt($con, 'SELECT id FROM tasks WHERE id=?', 'i', [$id]); $exists = mysqli_stmt_get_result($check); $found = mysqli_fetch_assoc($exists); mysqli_stmt_close($check);
            if (!$found) throw new RuntimeException('Task not found.');
            $stmt = taskStmt($con, 'INSERT INTO task_notes (task_id,note,created_by) VALUES (?,?,?)', 'iss', [$id,$note,$by]); mysqli_stmt_close($stmt);
            taskFlash('success','Note added.'); redirectTasks('view=' . $id);
        }
        if ($action === 'delete_task') {
            $id = (int)($_POST['id'] ?? 0); if ($id < 1) throw new RuntimeException('Invalid task.');
            $stmt = taskStmt($con, 'DELETE FROM tasks WHERE id=?', 'i', [$id]); mysqli_stmt_close($stmt); taskFlash('success','Task deleted.'); redirectTasks();
        }
        if ($action === 'save_daily_template') {
            $id = (int)($_POST['id'] ?? 0);
            $title = trim((string)($_POST['title'] ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));
            $recurrence = (string)($_POST['recurrence'] ?? 'Daily');
            $days = $_POST['days_of_week'] ?? [];
            if (!is_array($days)) $days = [];
            $validDays = ['1','2','3','4','5','6','7'];
            $days = array_values(array_unique(array_filter(array_map('strval', $days), function($d) use ($validDays) { return in_array($d, $validDays, true); })));
            $daysCsv = implode(',', $days);
            $priority = (string)($_POST['priority'] ?? 'Medium');
            $grade = (string)($_POST['grade'] ?? 'B');
            $assignee = trim((string)($_POST['assignee'] ?? ''));
            $targetTime = trim((string)($_POST['target_time'] ?? ''));
            $createdBy = trim((string)($_POST['created_by'] ?? ''));
            if ($title === '') throw new RuntimeException('Daily task title is required.');
            if (mb_strlen($title) > 200) throw new RuntimeException('Daily task title must be 200 characters or fewer.');
            if (!in_array($recurrence, ['Daily','Weekdays','Selected days'], true)) $recurrence = 'Daily';
            if ($recurrence === 'Selected days' && !$days) throw new RuntimeException('Choose at least one day for this routine.');
            if (!in_array($priority, $priorities, true)) $priority = 'Medium';
            if (!in_array($grade, $grades, true)) $grade = 'B';
            if ($targetTime !== '' && !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $targetTime)) throw new RuntimeException('Enter a valid target time.');
            $assignee = $assignee === '' ? null : $assignee;
            $targetTime = $targetTime === '' ? null : $targetTime . ':00';
            $createdBy = $createdBy === '' ? null : $createdBy;
            $daysValue = $recurrence === 'Selected days' ? $daysCsv : null;
            if ($id > 0) {
                $stmt = taskStmt($con, 'UPDATE daily_task_templates SET title=?, description=?, recurrence=?, days_of_week=?, priority=?, grade=?, assignee=?, target_time=?, created_by=? WHERE id=?', 'sssssssssi', [$title,$description,$recurrence,$daysValue,$priority,$grade,$assignee,$targetTime,$createdBy,$id]);
                mysqli_stmt_close($stmt); taskFlash('success', 'Daily routine updated.');
            } else {
                $stmt = taskStmt($con, 'INSERT INTO daily_task_templates (title,description,recurrence,days_of_week,priority,grade,assignee,target_time,created_by) VALUES (?,?,?,?,?,?,?,?,?)', 'sssssssss', [$title,$description,$recurrence,$daysValue,$priority,$grade,$assignee,$targetTime,$createdBy]);
                mysqli_stmt_close($stmt); taskFlash('success', 'Daily routine created. It will appear on scheduled days.');
            }
            redirectTasks('tab=daily');
        }
        if ($action === 'toggle_daily_template') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id < 1) throw new RuntimeException('Invalid daily routine.');
            $stmt = taskStmt($con, 'UPDATE daily_task_templates SET is_active=IF(is_active=1,0,1) WHERE id=?', 'i', [$id]); mysqli_stmt_close($stmt);
            taskFlash('success', 'Daily routine status updated.'); redirectTasks('tab=daily');
        }
        if ($action === 'delete_daily_template') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id < 1) throw new RuntimeException('Invalid daily routine.');
            $stmt = taskStmt($con, 'DELETE FROM daily_task_templates WHERE id=?', 'i', [$id]); mysqli_stmt_close($stmt);
            taskFlash('success', 'Daily routine and its daily history deleted.'); redirectTasks('tab=daily');
        }
        if ($action === 'daily_log_update') {
            $id = (int)($_POST['log_id'] ?? 0);
            if ($id < 1) throw new RuntimeException('Invalid daily task update.');
            $currentStmt = taskStmt($con, 'SELECT status, progress, note FROM daily_task_logs WHERE id=?', 'i', [$id]);
            $currentRes = mysqli_stmt_get_result($currentStmt); $currentLog = mysqli_fetch_assoc($currentRes); mysqli_stmt_close($currentStmt);
            if (!$currentLog) throw new RuntimeException('Daily task was not found.');
            $status = (string)($_POST['status'] ?? $currentLog['status']);
            if (!in_array($status, ['Pending','Completed','Skipped'], true)) throw new RuntimeException('Invalid daily task status.');
            $progress = isset($_POST['progress']) ? max(0, min(100, (int)$_POST['progress'])) : (int)$currentLog['progress'];
            $note = array_key_exists('note', $_POST) ? trim((string)$_POST['note']) : (string)($currentLog['note'] ?? '');
            if ($status === 'Completed') $progress = 100;
            elseif (isset($_POST['progress']) && $progress === 100) $status = 'Completed';
            elseif ($currentLog['status'] === 'Completed' && isset($_POST['progress']) && $progress < 100) $status = 'Pending';
            $completedAt = $status === 'Completed' ? date('Y-m-d H:i:s') : null;
            $stmt = taskStmt($con, 'UPDATE daily_task_logs SET status=?, progress=?, note=?, completed_at=? WHERE id=?', 'sissi', [$status,$progress,$note,$completedAt,$id]); mysqli_stmt_close($stmt);
            taskFlash('success', $status === 'Completed' ? 'Daily task marked complete.' : 'Daily task updated.'); redirectTasks('tab=daily');
        }
        throw new RuntimeException('Unknown action.');
    } catch (Throwable $e) { $error = $e->getMessage(); }
}

// Filters
$q = trim((string)($_GET['q'] ?? ''));
$fStatus = (string)($_GET['status'] ?? ''); $fPriority = (string)($_GET['priority'] ?? ''); $fGrade = (string)($_GET['grade'] ?? '');
$fCategory = (string)($_GET['category'] ?? ''); $fAssignee = trim((string)($_GET['assignee'] ?? '')); $fDue = (string)($_GET['due'] ?? ''); $fView = (string)($_GET['view'] ?? '');
$where = []; $types = ''; $params = [];
if ($q !== '') { $where[] = '(t.title LIKE ? OR t.description LIKE ? OR t.assignee LIKE ? OR t.created_by LIKE ? OR EXISTS (SELECT 1 FROM task_notes n WHERE n.task_id=t.id AND n.note LIKE ?))'; $like = '%' . $q . '%'; $types .= 'sssss'; array_push($params,$like,$like,$like,$like,$like); }
if (in_array($fStatus,$statuses,true)) { $where[]='t.status=?'; $types.='s'; $params[]=$fStatus; }
if (in_array($fPriority,$priorities,true)) { $where[]='t.priority=?'; $types.='s'; $params[]=$fPriority; }
if (in_array($fGrade,$grades,true)) { $where[]='t.grade=?'; $types.='s'; $params[]=$fGrade; }
if (in_array($fCategory,$categories,true)) { $where[]='t.category=?'; $types.='s'; $params[]=$fCategory; }
if ($fAssignee !== '') { $where[]='t.assignee LIKE ?'; $types.='s'; $params[]='%' . $fAssignee . '%'; }
if ($fDue === 'today') $where[]='t.due_date=CURDATE() AND t.status NOT IN (\'Completed\',\'Cancelled\')';
elseif ($fDue === 'upcoming') $where[]='t.due_date>CURDATE() AND t.status NOT IN (\'Completed\',\'Cancelled\')';
elseif ($fDue === 'overdue') $where[]='t.due_date<CURDATE() AND t.status NOT IN (\'Completed\',\'Cancelled\')';
if ($fView === 'important') $where[]="t.priority IN ('Urgent','High')";
elseif ($fView === 'mine') { $current = trim((string)($_SESSION['username'] ?? $_SESSION['user_name'] ?? '')); if ($current !== '') { $where[]='t.assignee=?'; $types.='s'; $params[]=$current; } }
elseif ($fView === 'completed') $where[]="t.status='Completed'";
$sqlWhere = $where ? ' WHERE ' . implode(' AND ', $where) : '';

// Dashboard totals are global (not affected by the current filters).
$stats = ['total'=>0,'active'=>0,'completed'=>0,'overdue'=>0,'urgent'=>0,'avg'=>0];
$sr = mysqli_query($con, "SELECT COUNT(*) total, SUM(status NOT IN ('Completed','Cancelled')) active, SUM(status='Completed') completed, SUM(due_date<CURDATE() AND status NOT IN ('Completed','Cancelled')) overdue, SUM(priority='Urgent' AND status NOT IN ('Completed','Cancelled')) urgent, ROUND(AVG(progress)) avg_progress FROM tasks");
if ($sr && ($srow=mysqli_fetch_assoc($sr))) { $stats['total']=(int)$srow['total']; $stats['active']=(int)$srow['active']; $stats['completed']=(int)$srow['completed']; $stats['overdue']=(int)$srow['overdue']; $stats['urgent']=(int)$srow['urgent']; $stats['avg']=(int)$srow['avg_progress']; }

$countStmt = taskStmt($con, 'SELECT COUNT(*) AS c FROM tasks t' . $sqlWhere, $types, $params); $countRes=mysqli_stmt_get_result($countStmt); $totalRows=(int)(mysqli_fetch_assoc($countRes)['c'] ?? 0); mysqli_stmt_close($countStmt);
$page = max(1,(int)($_GET['page'] ?? 1)); $perPage=15; $totalPages=max(1,(int)ceil($totalRows/$perPage)); if($page>$totalPages)$page=$totalPages; $offset=($page-1)*$perPage;
$listSql = 'SELECT t.* FROM tasks t' . $sqlWhere . " ORDER BY CASE t.priority WHEN 'Urgent' THEN 1 WHEN 'High' THEN 2 WHEN 'Medium' THEN 3 ELSE 4 END, CASE WHEN t.due_date IS NULL THEN 1 ELSE 0 END, t.due_date ASC, t.updated_at DESC LIMIT ? OFFSET ?";
$listTypes=$types.'ii'; $listParams=$params; $listParams[]=$perPage; $listParams[]=$offset;
$listStmt=taskStmt($con,$listSql,$listTypes,$listParams); $listRes=mysqli_stmt_get_result($listStmt); $tasks=[]; while($r=mysqli_fetch_assoc($listRes))$tasks[]=$r; mysqli_stmt_close($listStmt);

$viewTask = null; $viewNotes=[];
$viewId=(int)($_GET['view'] ?? 0);
if ($viewId>0) { $st=taskStmt($con,'SELECT * FROM tasks WHERE id=?','i',[$viewId]); $rr=mysqli_stmt_get_result($st); $viewTask=mysqli_fetch_assoc($rr); mysqli_stmt_close($st); if($viewTask){$st=taskStmt($con,'SELECT * FROM task_notes WHERE task_id=? ORDER BY created_at DESC,id DESC','i',[$viewId]);$rr=mysqli_stmt_get_result($st);while($n=mysqli_fetch_assoc($rr))$viewNotes[]=$n;mysqli_stmt_close($st);} }
$flash=$_SESSION['task_flash'] ?? null; unset($_SESSION['task_flash']);
$activeTab = (($_GET['tab'] ?? '') === 'daily') ? 'daily' : 'tasks';
$today = date('Y-m-d');
$dailyTemplates = [];
$dailyLogs = [];
$dailySummary = ['total'=>0,'completed'=>0,'pending'=>0,'skipped'=>0,'progress'=>0];
$editDailyId = (int)($_GET['edit_daily'] ?? 0);
$editDaily = null;
if ($activeTab === 'daily') {
    // Create today's occurrence once for each active routine scheduled for this weekday.
    $dayNumber = (int)date('N');
    $tr = mysqli_query($con, "SELECT * FROM daily_task_templates WHERE is_active=1 ORDER BY COALESCE(target_time,'23:59:59'), title");
    if ($tr) while ($tpl = mysqli_fetch_assoc($tr)) {
        $scheduled = $tpl['recurrence'] === 'Daily'
            || ($tpl['recurrence'] === 'Weekdays' && $dayNumber <= 5)
            || ($tpl['recurrence'] === 'Selected days' && in_array((string)$dayNumber, explode(',', (string)$tpl['days_of_week']), true));
        if ($scheduled) {
            $ins = taskStmt($con, "INSERT IGNORE INTO daily_task_logs (template_id, task_date) VALUES (?,?)", 'is', [(int)$tpl['id'], $today]);
            mysqli_stmt_close($ins);
        }
    }
    $lr = taskStmt($con, 'SELECT l.*, t.title, t.description, t.priority, t.grade, t.assignee, t.target_time, t.is_active, t.recurrence FROM daily_task_logs l INNER JOIN daily_task_templates t ON t.id=l.template_id WHERE l.task_date=? ORDER BY FIELD(l.status,"Pending","Skipped","Completed"), COALESCE(t.target_time,"23:59:59"), t.title', 's', [$today]);
    $lres = mysqli_stmt_get_result($lr);
    while ($row = mysqli_fetch_assoc($lres)) { $dailyLogs[] = $row; $dailySummary['total']++; $dailySummary[strtolower($row['status'])]++; $dailySummary['progress'] += (int)($row['progress'] ?? 0); }
    if ($dailySummary['total'] > 0) $dailySummary['progress'] = (int)round($dailySummary['progress'] / $dailySummary['total']);
    mysqli_stmt_close($lr);
    $dr = mysqli_query($con, "SELECT * FROM daily_task_templates ORDER BY is_active DESC, title");
    if ($dr) while ($row = mysqli_fetch_assoc($dr)) $dailyTemplates[] = $row;
    if ($editDailyId > 0) {
        $ed = taskStmt($con, 'SELECT * FROM daily_task_templates WHERE id=?', 'i', [$editDailyId]);
        $er = mysqli_stmt_get_result($ed); $editDaily = mysqli_fetch_assoc($er); mysqli_stmt_close($ed);
    }
}
$editId=(int)($_GET['edit'] ?? 0); $editTask=null;
if($editId>0){$st=taskStmt($con,'SELECT * FROM tasks WHERE id=?','i',[$editId]);$rr=mysqli_stmt_get_result($st);$editTask=mysqli_fetch_assoc($rr);mysqli_stmt_close($st);}
$filterQuery=$_GET; unset($filterQuery['page']); $filterUrl=http_build_query($filterQuery);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Task Management | The Divine Lands</title>
<style>
:root{--ink:#182230;--muted:#6b7788;--line:#e6eaf0;--paper:#f5f7fb;--white:#fff;--navy:#172554;--blue:#3563e9;--blue-soft:#eef3ff;--green:#16845b;--green-soft:#e9f8f0;--red:#c93442;--red-soft:#fff0f1;--amber:#a96508;--amber-soft:#fff6e6;--purple:#7048c8;--shadow:0 10px 35px rgba(25,43,77,.06)}*{box-sizing:border-box}body{margin:0;background:var(--paper);color:var(--ink);font:14px/1.45 Inter,ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif}.app{max-width:1500px;margin:auto;padding:28px}.top{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:24px}.brand{display:flex;align-items:center;gap:13px}.logo{width:48px;height:48px;border-radius:15px;background:linear-gradient(145deg,#1d3270,#3563e9);display:grid;place-items:center;color:#fff;font-size:23px;box-shadow:0 7px 16px #2547a52b}.eyebrow{font-size:11px;letter-spacing:1.6px;text-transform:uppercase;color:var(--muted);font-weight:800}.brand h1{font-size:25px;line-height:1.2;margin:2px 0 0;letter-spacing:-.7px}.brand p{margin:5px 0 0;color:var(--muted)}.btn{border:0;border-radius:10px;padding:11px 15px;display:inline-flex;align-items:center;justify-content:center;gap:8px;font-weight:750;text-decoration:none;cursor:pointer;transition:.18s}.btn:hover{transform:translateY(-1px)}.primary{background:var(--blue);color:#fff;box-shadow:0 6px 14px #3563e926}.secondary{background:#fff;color:#344054;border:1px solid var(--line)}.danger{background:var(--red-soft);color:var(--red)}.stats{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:14px;margin-bottom:20px}.stat{background:#fff;border:1px solid var(--line);border-radius:17px;padding:18px;box-shadow:var(--shadow);min-width:0}.stat-top{display:flex;justify-content:space-between;align-items:center;color:var(--muted);font-size:12px;font-weight:700}.stat-icon{width:34px;height:34px;border-radius:11px;display:grid;place-items:center;font-size:17px;background:var(--blue-soft);color:var(--blue)}.stat strong{display:block;font-size:29px;letter-spacing:-1px;margin:10px 0 3px}.stat small{color:var(--muted)}.overview{background:#fff;border:1px solid var(--line);border-radius:17px;padding:18px 20px;margin-bottom:20px;box-shadow:var(--shadow)}.overview-head{display:flex;justify-content:space-between;gap:12px;margin-bottom:10px}.overview-head strong{font-size:14px}.overview-head span{font-weight:800;color:var(--blue)}.track{height:9px;border-radius:99px;background:#edf0f5;overflow:hidden}.track span{display:block;height:100%;background:linear-gradient(90deg,#3563e9,#63a0ff);border-radius:99px;transition:width .25s}.overview-foot{display:flex;justify-content:space-between;gap:10px;color:var(--muted);font-size:12px;margin-top:9px}.panel{background:#fff;border:1px solid var(--line);border-radius:17px;box-shadow:var(--shadow);overflow:hidden}.panel-head{padding:19px 20px;border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;gap:14px}.panel-head h2{font-size:17px;margin:0;letter-spacing:-.3px}.panel-head p{color:var(--muted);margin:4px 0 0;font-size:12px}.filters{padding:16px 20px;background:#fbfcfe;border-bottom:1px solid var(--line)}.filter-grid{display:grid;grid-template-columns:minmax(180px,2fr) repeat(5,minmax(125px,1fr));gap:9px}.field{display:flex;flex-direction:column;gap:6px;min-width:0}.field label{font-size:11px;font-weight:800;color:#667085}.field input,.field select,.field textarea{width:100%;border:1px solid #dce2eb;background:#fff;border-radius:9px;padding:10px 11px;color:var(--ink);font:inherit;outline:none;min-width:0}.field input:focus,.field select:focus,.field textarea:focus{border-color:#7594ff;box-shadow:0 0 0 3px #3563e916}.filter-actions{display:flex;gap:8px;align-items:end}.quick-links{display:flex;gap:8px;flex-wrap:wrap;margin-top:13px}.quick{border:1px solid var(--line);background:#fff;color:#596579;border-radius:99px;padding:7px 11px;font-size:12px;font-weight:750;text-decoration:none}.quick.active,.quick:hover{background:var(--blue-soft);color:var(--blue);border-color:#cdd9ff}.table-wrap{width:100%;overflow-x:auto}table{border-collapse:collapse;width:100%;min-width:1060px}th{font-size:10px;text-transform:uppercase;letter-spacing:.8px;color:#7b8797;text-align:left;background:#fff;padding:13px 15px;border-bottom:1px solid var(--line);white-space:nowrap}td{padding:15px;border-bottom:1px solid #edf0f5;vertical-align:middle}tbody tr:hover{background:#fbfcff}tbody tr:last-child td{border-bottom:0}.task-title{font-weight:800;color:#202c3c;text-decoration:none}.task-title:hover{color:var(--blue)}.task-desc{font-size:12px;color:var(--muted);margin-top:4px;max-width:310px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.pill{display:inline-flex;align-items:center;gap:5px;border-radius:99px;padding:5px 9px;font-size:11px;font-weight:800;white-space:nowrap}.pill:before{content:'';width:6px;height:6px;border-radius:50%;background:currentColor}.p-urgent{background:var(--red-soft);color:var(--red)}.p-high{background:var(--amber-soft);color:var(--amber)}.p-medium{background:var(--blue-soft);color:var(--blue)}.p-low{background:#f1f3f6;color:#657184}.s-todo{background:#f1f3f6;color:#657184}.s-progress{background:var(--blue-soft);color:var(--blue)}.s-hold,.s-review{background:var(--amber-soft);color:var(--amber)}.s-blocked{background:var(--red-soft);color:var(--red)}.s-completed{background:var(--green-soft);color:var(--green)}.s-cancelled{background:#f1f3f6;color:#7d8795}.grade{display:inline-grid;place-items:center;width:28px;height:28px;border-radius:9px;font-weight:900;font-size:12px}.grade-A{background:#e7f8ef;color:#128052}.grade-B{background:#eaf0ff;color:#315bd5}.grade-C{background:#fff4dc;color:#a36b00}.grade-D{background:#ffebee;color:#c23543}.progress-cell{min-width:130px}.progress-line{display:flex;align-items:center;gap:8px}.progress-line .track{height:6px;flex:1}.progress-line .track span{background:#4774ee}.progress-number{font-size:11px;color:#596579;font-weight:800;min-width:30px;text-align:right}.due{font-size:12px;font-weight:750;white-space:nowrap}.due.overdue{color:var(--red)}.due.today{color:var(--amber)}.due.done{color:var(--green)}.actions{display:flex;gap:5px}.icon-btn{border:1px solid var(--line);background:#fff;color:#596579;border-radius:8px;width:31px;height:31px;display:grid;place-items:center;text-decoration:none;cursor:pointer}.icon-btn:hover{border-color:#c8d4ff;background:var(--blue-soft);color:var(--blue)}.empty{padding:50px 20px;text-align:center;color:var(--muted)}.empty .big{font-size:36px;margin-bottom:8px}.pagination{display:flex;align-items:center;justify-content:space-between;padding:15px 20px;border-top:1px solid var(--line);color:var(--muted);font-size:12px}.pages{display:flex;gap:6px}.pages a{padding:7px 10px;border:1px solid var(--line);border-radius:8px;color:#475467;text-decoration:none;background:#fff}.pages a.current{background:var(--blue);color:#fff;border-color:var(--blue)}.alert{padding:12px 15px;border-radius:11px;margin-bottom:16px;font-weight:700}.alert.success{background:var(--green-soft);color:var(--green)}.alert.error{background:var(--red-soft);color:var(--red)}.modal-backdrop{position:fixed;inset:0;background:#0d1734a6;display:none;align-items:center;justify-content:center;padding:20px;z-index:50;overflow:auto}.modal-backdrop.open{display:flex}.modal{width:min(820px,100%);max-height:94vh;overflow:auto;background:#fff;border-radius:20px;box-shadow:0 30px 90px #07132d45}.modal-head{padding:21px 24px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;align-items:center;position:sticky;top:0;background:#fff;z-index:1}.modal-head h2{margin:0;font-size:19px}.close{border:0;background:#f1f3f6;border-radius:9px;width:34px;height:34px;font-size:21px;cursor:pointer}.modal-body{padding:22px 24px}.form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:15px}.full{grid-column:1/-1}.field textarea{min-height:100px;resize:vertical}.form-footer{display:flex;justify-content:flex-end;gap:9px;padding-top:20px;margin-top:20px;border-top:1px solid var(--line)}.details-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-bottom:18px}.detail-box{background:#f8faff;border:1px solid var(--line);border-radius:12px;padding:12px}.detail-box small{display:block;color:var(--muted);font-size:10px;text-transform:uppercase;letter-spacing:.6px;font-weight:800}.detail-box strong{display:block;margin-top:5px}.detail-description{white-space:pre-wrap;line-height:1.65;color:#465366}.notes{margin-top:22px}.notes h3{margin:0 0 12px;font-size:15px}.note{border:1px solid var(--line);border-radius:12px;padding:12px;margin-bottom:9px}.note-meta{font-size:11px;color:var(--muted);margin-bottom:6px}.note-text{white-space:pre-wrap;overflow-wrap:anywhere}.inline-update{display:flex;align-items:center;gap:7px}.inline-update select{max-width:125px;border:1px solid var(--line);border-radius:7px;padding:5px;font-size:11px}.inline-update button{border:0;background:var(--blue-soft);color:var(--blue);border-radius:7px;padding:6px 8px;font-weight:800;cursor:pointer;font-size:11px}.footer{padding:18px 0;text-align:center;color:#8a94a4;font-size:11px}.mobile-hint{display:none;color:var(--muted);font-size:11px;padding:0 20px 12px}
@media(max-width:1100px){.stats{grid-template-columns:repeat(3,minmax(0,1fr))}.filter-grid{grid-template-columns:repeat(3,minmax(0,1fr))}.filter-grid .search-field{grid-column:span 2}}@media(max-width:700px){.app{padding:15px}.top{align-items:flex-start}.brand h1{font-size:21px}.brand p{max-width:240px}.top .btn{padding:10px;font-size:12px}.logo{width:42px;height:42px}.stats{grid-template-columns:repeat(2,minmax(0,1fr));gap:9px}.stat{padding:14px}.stat strong{font-size:25px}.stat:last-child{grid-column:span 2}.filter-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.filter-grid .search-field{grid-column:span 2}.filter-actions{grid-column:span 2}.filter-actions .btn{flex:1}.panel-head{padding:16px}.filters{padding:13px}.mobile-hint{display:block}.pagination{gap:10px;flex-wrap:wrap}.form-grid{grid-template-columns:1fr}.full{grid-column:auto}.modal-body{padding:17px}.modal-head{padding:17px}.details-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.overview{padding:15px}.overview-foot{flex-wrap:wrap}}
.tabs{display:flex;gap:8px;margin:0 0 18px;flex-wrap:wrap}.tab-link{display:inline-flex;gap:8px;align-items:center;padding:10px 14px;border:1px solid var(--line);border-radius:11px;background:#fff;color:#526071;text-decoration:none;font-weight:800}.tab-link.active{background:var(--navy);border-color:var(--navy);color:#fff}.daily-layout{display:grid;grid-template-columns:minmax(0,1.5fr) minmax(300px,1fr);gap:18px}.daily-card{background:#fff;border:1px solid var(--line);border-radius:16px;box-shadow:var(--shadow);overflow:hidden;margin-bottom:16px}.daily-card-head{padding:17px 19px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;align-items:center;gap:12px}.daily-card-head h2{font-size:16px;margin:0}.daily-card-head p{font-size:12px;color:var(--muted);margin:4px 0 0}.daily-list{padding:8px 18px}.daily-item{display:grid;grid-template-columns:26px minmax(0,1fr) auto;gap:12px;align-items:start;padding:15px 0;border-bottom:1px solid #edf0f5}.daily-item:last-child{border-bottom:0}.daily-check{width:22px;height:22px;accent-color:var(--green);margin-top:2px}.daily-title{font-weight:850}.daily-item.done .daily-title{text-decoration:line-through;color:#84909f}.daily-progress{margin-top:10px;max-width:390px}.daily-progress-head{display:flex;justify-content:space-between;align-items:center;gap:8px;font-size:11px;color:var(--muted);font-weight:800;margin-bottom:6px}.daily-progress-line{display:flex;align-items:center;gap:8px}.daily-progress-line .track{height:7px;flex:1}.daily-progress-line .track span{background:#4774ee}.daily-progress-number{font-size:11px;color:#596579;font-weight:800;min-width:34px;text-align:right}.daily-progress-form{display:flex;align-items:center;gap:7px;flex-wrap:wrap;margin-top:8px}.daily-progress-form select{border:1px solid var(--line);border-radius:7px;padding:6px 8px;font-size:11px;background:#fff;color:var(--ink)}.daily-meta{display:flex;flex-wrap:wrap;gap:7px;margin-top:7px;font-size:11px;color:var(--muted)}.daily-note{width:100%;margin-top:9px;border:1px solid var(--line);border-radius:8px;padding:8px;font:inherit;font-size:12px}.daily-actions{display:flex;gap:6px;flex-wrap:wrap}.small-btn{border:1px solid var(--line);border-radius:8px;background:#fff;padding:7px 9px;cursor:pointer;font-weight:750;font-size:11px;color:#465366}.small-btn.complete{background:var(--green-soft);color:var(--green);border-color:#ccebd9}.routine-list{padding:0 18px}.routine{padding:14px 0;border-bottom:1px solid #edf0f5}.routine:last-child{border-bottom:0}.routine-top{display:flex;justify-content:space-between;gap:10px}.routine-title{font-weight:850}.routine-description{color:var(--muted);font-size:12px;margin-top:4px}.routine-actions{display:flex;gap:6px;flex-wrap:wrap;margin-top:10px}.daily-stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-bottom:18px}.daily-stat{padding:14px;background:#fff;border:1px solid var(--line);border-radius:13px}.daily-stat small{display:block;color:var(--muted);font-weight:750}.daily-stat strong{display:block;font-size:23px;margin-top:5px}.days-picker{display:flex;flex-wrap:wrap;gap:8px}.days-picker label{display:flex;align-items:center;gap:5px;border:1px solid var(--line);border-radius:8px;padding:7px 9px;font-size:12px}.days-picker input{width:auto}.routine-muted{opacity:.58}.history-note{font-size:11px;color:var(--muted);margin-top:6px}@media(max-width:900px){.daily-layout{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="app">
<header class="top"><div class="brand"><div class="logo">✓</div><div><div class="eyebrow">THE DIVINE LANDS · WORKSPACE</div><h1>Task Management</h1><p>Prioritise the right work. Keep every detail moving.</p></div></div>
<button class="btn primary" type="button" onclick="window.location.href='index.php'">ALL</button>
<!-- <button class="btn btn-primary" type="button" onclick="window.location.href='index.php'">ALL</button> -->

</header>

<?php if ($error !== ''): ?><div class="alert error"><?=h($error)?></div><?php endif; ?>
<?php if ($flash): ?><div class="alert <?=h($flash['type'])?>"><?=h($flash['message'])?></div><?php endif; ?>
<nav class="tabs"><a class="tab-link <?=($activeTab==='tasks'?'active':'')?>" href="<?=h($selfPage)?>">▤ All Tasks</a><a class="tab-link <?=($activeTab==='daily'?'active':'')?>" href="<?=h($selfPage)?>?tab=daily">☑ Daily Tasks & Routines</a></nav>
<?php if ($activeTab === 'daily'): ?>
<section class="daily-stats">
<div class="daily-stat"><small>Scheduled today</small><strong><?=number_format($dailySummary['total'])?></strong></div>
<div class="daily-stat"><small>Completed</small><strong style="color:var(--green)"><?=number_format($dailySummary['completed'])?></strong></div>
<div class="daily-stat"><small>Still to do</small><strong style="color:var(--amber)"><?=number_format($dailySummary['pending'])?></strong></div>
</section>
<section class="overview"><div class="overview-head"><strong>Today's routine progress</strong><span><?=$dailySummary['progress']?>% complete</span></div><div class="track"><span style="width:<?=$dailySummary['progress']?>%;background:var(--green)"></span></div><div class="overview-foot"><span><?=h(date('l, d M Y'))?></span><span>Average progress across <?=number_format($dailySummary['total'])?> scheduled tasks</span></div></section>
<div class="daily-layout"><div>
<section class="daily-card"><div class="daily-card-head"><div><h2>Today's checklist</h2><p>Each date has its own completion record, so previous days remain in history.</p></div><span class="pill p-medium"><?=count($dailyLogs)?> tasks</span></div>
<div class="daily-list">
<?php if (!$dailyLogs): ?><div class="empty"><div class="big">☀</div><strong>No routines scheduled today</strong><p>Create a daily routine on the right and it will appear here on its scheduled days.</p></div><?php endif; ?>
<?php foreach ($dailyLogs as $log): ?><div class="daily-item <?=($log['status']==='Completed'?'done':'')?>">
<form method="post" style="display:contents"><input type="hidden" name="csrf" value="<?=h($csrf)?>"><input type="hidden" name="action" value="daily_log_update"><input type="hidden" name="log_id" value="<?=(int)$log['id']?>"><input type="hidden" name="status" value="<?=($log['status']==='Completed'?'Pending':'Completed')?>"><input type="hidden" name="progress" value="<?=($log['status']==='Completed'?0:100)?>"><input class="daily-check" type="checkbox" <?=($log['status']==='Completed'?'checked':'')?> onchange="this.form.submit()" aria-label="Mark <?=h($log['title'])?> complete"></form>
<div><div class="daily-title"><?=h($log['title'])?></div><?php if ($log['description']): ?><div class="routine-description"><?=h($log['description'])?></div><?php endif; ?><div class="daily-meta"><span class="pill p-<?=h(strtolower($log['priority']))?>"><?=h($log['priority'])?></span><span>Grade <?=h($log['grade'])?></span><?php if($log['assignee']):?><span>Assigned: <?=h($log['assignee'])?></span><?php endif;?><?php if($log['target_time']):?><span>Target: <?=h(date('h:i A',strtotime($log['target_time'])))?></span><?php endif; ?><span><?=h($log['status'])?></span></div>
<div class="daily-progress"><div class="daily-progress-head"><span>Task progress</span><span><?= (int)($log['progress'] ?? 0) ?>%</span></div><div class="daily-progress-line"><div class="track"><span style="width:<?=min(100,max(0,(int)($log['progress'] ?? 0)))?>%;<?=((int)($log['progress'] ?? 0)===100?'background:var(--green)':'')?>"></span></div><span class="daily-progress-number"><?= (int)($log['progress'] ?? 0) ?>%</span></div><form class="daily-progress-form" method="post"><input type="hidden" name="csrf" value="<?=h($csrf)?>"><input type="hidden" name="action" value="daily_log_update"><input type="hidden" name="log_id" value="<?=(int)$log['id']?>"><input type="hidden" name="status" value="<?=h($log['status'])?>"><label for="daily_progress_<?=(int)$log['id']?>" class="eyebrow">Update</label><select id="daily_progress_<?=(int)$log['id']?>" name="progress"><?php foreach([0,10,20,30,40,50,60,70,80,90,100] as $pv):?><option value="<?=$pv?>" <?=((int)($log['progress'] ?? 0)===$pv?'selected':'')?>><?=$pv?>%</option><?php endforeach;?></select><button class="small-btn" type="submit">Save progress</button></form></div>
<form method="post"><input type="hidden" name="csrf" value="<?=h($csrf)?>"><input type="hidden" name="action" value="daily_log_update"><input type="hidden" name="log_id" value="<?=(int)$log['id']?>"><input type="hidden" name="status" value="<?=h($log['status'])?>"><input type="hidden" name="progress" value="<?=(int)($log['progress'] ?? 0)?>"><textarea class="daily-note" name="note" rows="2" placeholder="Today's note / result / follow-up…"><?=h($log['note'])?></textarea><div style="display:flex;justify-content:flex-end;margin-top:6px"><button class="small-btn" type="submit">Save note</button></div></form>
<?php if($log['completed_at']):?><div class="history-note">Completed at <?=h(date('h:i A',strtotime($log['completed_at'])))?></div><?php endif;?></div>
<div class="daily-actions"><?php if($log['status']==='Pending'):?><form method="post"><input type="hidden" name="csrf" value="<?=h($csrf)?>"><input type="hidden" name="action" value="daily_log_update"><input type="hidden" name="log_id" value="<?=(int)$log['id']?>"><input type="hidden" name="status" value="Skipped"><input type="hidden" name="progress" value="<?=(int)($log['progress'] ?? 0)?>"><button class="small-btn" type="submit">Skip</button></form><?php elseif($log['status']==='Skipped'):?><span class="pill p-low">Skipped</span><?php else:?><span class="pill s-completed">Done</span><?php endif;?></div>
</div><?php endforeach; ?>
</div></section>
</div><div>
<section class="daily-card"><div class="daily-card-head"><div><h2><?=($editDaily?'Edit routine':'Create a fixed routine')?></h2><p>Set once; it automatically appears on matching dates.</p></div></div>
<form method="post" class="modal-body" style="padding:18px"><input type="hidden" name="csrf" value="<?=h($csrf)?>"><input type="hidden" name="action" value="save_daily_template"><input type="hidden" name="id" value="<?=(int)($editDaily['id'] ?? 0)?>">
<div class="form-grid">
<div class="field full"><label>Routine title *</label><input required maxlength="200" name="title" value="<?=h($editDaily['title'] ?? '')?>" placeholder="e.g. Follow up with 10 land owners"></div>
<div class="field full"><label>Description / expected result</label><textarea name="description" placeholder="What should be done every time?"><?=h($editDaily['description'] ?? '')?></textarea></div>
<div class="field"><label>Repeat</label><select name="recurrence" id="dailyRecurrence" onchange="toggleDaysPicker()"><option <?=(($editDaily['recurrence'] ?? 'Daily')==='Daily'?'selected':'')?> value="Daily">Every day</option><option <?=(($editDaily['recurrence'] ?? '')==='Weekdays'?'selected':'')?> value="Weekdays">Monday–Friday</option><option <?=(($editDaily['recurrence'] ?? '')==='Selected days'?'selected':'')?> value="Selected days">Selected weekdays</option></select></div>
<div class="field"><label>Target time (optional)</label><input type="time" name="target_time" value="<?=h(isset($editDaily['target_time']) && $editDaily['target_time'] ? substr($editDaily['target_time'],0,5) : '')?>"></div>
<div class="field"><label>Priority</label><select name="priority"><?php foreach($priorities as $v):?><option value="<?=h($v)?>" <?=((($editDaily['priority'] ?? 'Medium')===$v)?'selected':'')?>><?=h($v)?></option><?php endforeach;?></select></div>
<div class="field"><label>Business grade</label><select name="grade"><?php foreach($grades as $v):?><option value="<?=h($v)?>" <?=((($editDaily['grade'] ?? 'B')===$v)?'selected':'')?>>Grade <?=h($v)?></option><?php endforeach;?></select></div>
<div class="field"><label>Assigned to</label><input name="assignee" value="<?=h($editDaily['assignee'] ?? '')?>" placeholder="Optional team member"></div>
<div class="field"><label>Created by</label><input name="created_by" value="<?=h($editDaily['created_by'] ?? ($_SESSION['username'] ?? $_SESSION['user_name'] ?? ''))?>"></div>
<div class="field full" id="daysPicker" style="display:none"><label>Repeat on</label><div class="days-picker"><?php $selectedDays=explode(',',(string)($editDaily['days_of_week'] ?? '')); foreach(['1'=>'Mon','2'=>'Tue','3'=>'Wed','4'=>'Thu','5'=>'Fri','6'=>'Sat','7'=>'Sun'] as $num=>$day):?><label><input type="checkbox" name="days_of_week[]" value="<?=h($num)?>" <?=in_array($num,$selectedDays,true)?'checked':''?>><?=h($day)?></label><?php endforeach;?></div></div>
</div><div class="form-footer"><a class="btn secondary" href="<?=h($selfPage)?>?tab=daily">Clear</a><button class="btn primary" type="submit"><?=($editDaily?'Update routine':'＋ Add routine')?></button></div></form>
</section>
<section class="daily-card"><div class="daily-card-head"><div><h2>Routine library</h2><p>Pause routines when you don't need them; history is retained.</p></div><span class="pill p-medium"><?=count($dailyTemplates)?></span></div><div class="routine-list">
<?php if(!$dailyTemplates):?><div class="empty"><strong>No routines created</strong><p>Add your first routine above.</p></div><?php endif;?>
<?php foreach($dailyTemplates as $tpl):?><div class="routine <?=(!$tpl['is_active']?'routine-muted':'')?>"><div class="routine-top"><div><div class="routine-title"><?=h($tpl['title'])?></div><div class="routine-description"><?=h($tpl['description'] ?: 'No description')?></div><div class="daily-meta"><span><?=h($tpl['recurrence'])?></span><?php if($tpl['recurrence']==='Selected days' && $tpl['days_of_week']):?><span><?=h(str_replace(['1','2','3','4','5','6','7'],['Mon','Tue','Wed','Thu','Fri','Sat','Sun'],$tpl['days_of_week']))?></span><?php endif;?><span><?=h($tpl['priority'])?> · Grade <?=h($tpl['grade'])?></span><?php if($tpl['target_time']):?><span><?=h(date('h:i A',strtotime($tpl['target_time'])))?></span><?php endif;?></div></div><span class="pill <?=($tpl['is_active']?'s-completed':'s-cancelled')?>"><?=($tpl['is_active']?'Active':'Paused')?></span></div>
<div class="routine-actions"><a class="small-btn" href="<?=h($selfPage)?>?tab=daily&amp;edit_daily=<?=(int)$tpl['id']?>">Edit</a><form method="post"><input type="hidden" name="csrf" value="<?=h($csrf)?>"><input type="hidden" name="action" value="toggle_daily_template"><input type="hidden" name="id" value="<?=(int)$tpl['id']?>"><button class="small-btn" type="submit"><?=($tpl['is_active']?'Pause':'Resume')?></button></form><form method="post" onsubmit="return confirm('Delete this routine and all its daily history? This cannot be undone.')"><input type="hidden" name="csrf" value="<?=h($csrf)?>"><input type="hidden" name="action" value="delete_daily_template"><input type="hidden" name="id" value="<?=(int)$tpl['id']?>"><button class="small-btn" type="submit" style="color:var(--red)">Delete</button></form></div></div><?php endforeach;?>
</div></section>
</div></div>
<?php else: ?>
<section class="stats">
<div class="stat"><div class="stat-top">All tasks <span class="stat-icon">▤</span></div><strong><?=number_format($stats['total'])?></strong><small>Tasks in your workspace</small></div>
<div class="stat"><div class="stat-top">Active work <span class="stat-icon">◷</span></div><strong><?=number_format($stats['active'])?></strong><small>Not completed or cancelled</small></div>
<div class="stat"><div class="stat-top">Completed <span class="stat-icon" style="background:var(--green-soft);color:var(--green)">✓</span></div><strong><?=number_format($stats['completed'])?></strong><small>Successfully finished</small></div>
<div class="stat"><div class="stat-top">Overdue <span class="stat-icon" style="background:var(--red-soft);color:var(--red)">!</span></div><strong><?=number_format($stats['overdue'])?></strong><small>Deadline has passed</small></div>
<div class="stat"><div class="stat-top">Urgent <span class="stat-icon" style="background:var(--amber-soft);color:var(--amber)">⚑</span></div><strong><?=number_format($stats['urgent'])?></strong><small>Urgent tasks still open</small></div>
</section>
<section class="overview"><div class="overview-head"><strong>Workspace progress</strong><span><?=$stats['avg']?>% average task completion</span></div><div class="track"><span style="width:<?=$stats['avg']?>%"></span></div><div class="overview-foot"><span>Based on progress values across all tasks</span><span><?=number_format($stats['completed'])?> of <?=number_format($stats['total'])?> completed</span></div></section>
<section class="panel">
<div class="panel-head"><div><h2>Tasks & priorities</h2><p><?=number_format($totalRows)?> matching task<?=($totalRows===1?'':'s')?> · sorted by urgency and due date</p></div><button type="button" class="btn secondary" onclick="openTaskModal()">＋ Add task</button></div>
<form class="filters" method="get" action="<?=h($selfPage)?>"><div class="filter-grid">
<div class="field search-field"><label for="q">Search</label><input id="q" name="q" value="<?=h($q)?>" placeholder="Title, description, notes, assignee…"></div>
<div class="field"><label for="status">Status</label><select id="status" name="status"><option value="">All statuses</option><?php foreach($statuses as $v):?><option <?=($fStatus===$v?'selected':'')?> value="<?=h($v)?>"><?=h($v)?></option><?php endforeach;?></select></div>
<div class="field"><label for="priority">Priority</label><select id="priority" name="priority"><option value="">All priorities</option><?php foreach($priorities as $v):?><option <?=($fPriority===$v?'selected':'')?> value="<?=h($v)?>"><?=h($v)?></option><?php endforeach;?></select></div>
<div class="field"><label for="grade">Grade</label><select id="grade" name="grade"><option value="">All grades</option><?php foreach($grades as $v):?><option <?=($fGrade===$v?'selected':'')?> value="<?=h($v)?>">Grade <?=h($v)?></option><?php endforeach;?></select></div>
<div class="field"><label for="category">Category</label><select id="category" name="category"><option value="">All categories</option><?php foreach($categories as $v):?><option <?=($fCategory===$v?'selected':'')?> value="<?=h($v)?>"><?=h($v)?></option><?php endforeach;?></select></div>
<div class="field"><label for="assignee">Assigned to</label><input id="assignee" name="assignee" value="<?=h($fAssignee)?>" placeholder="Team member"></div>
<div class="field"><label for="due">Deadline</label><select id="due" name="due"><option value="">Any deadline</option><option value="today" <?=($fDue==='today'?'selected':'')?>>Due today</option><option value="upcoming" <?=($fDue==='upcoming'?'selected':'')?>>Upcoming</option><option value="overdue" <?=($fDue==='overdue'?'selected':'')?>>Overdue</option></select></div>
<div class="filter-actions"><button class="btn primary" type="submit">⌕ Filter</button><a class="btn secondary" href="<?=h($selfPage)?>">Reset</a></div>
</div><div class="quick-links"><a class="quick <?=($fView==='important'?'active':'')?>" href="?view=important">⚑ Important</a><a class="quick <?=($fView==='mine'?'active':'')?>" href="?view=mine">♙ My tasks</a><a class="quick <?=($fView==='completed'?'active':'')?>" href="?view=completed">✓ Completed</a><a class="quick" href="?due=overdue">◷ Overdue</a><a class="quick" href="<?=h($selfPage)?>">All tasks</a></div></form>
<div class="mobile-hint">Swipe horizontally to view all task details and actions.</div>
<div class="table-wrap"><table><thead><tr><th>Task</th><th>Priority</th><th>Grade</th><th>Status</th><th>Progress</th><th>Assignee</th><th>Due date</th><th>Actions</th></tr></thead><tbody>
<?php if(!$tasks):?><tr><td colspan="8"><div class="empty"><div class="big">▤</div><strong>No tasks found</strong><p>Try changing the filters or create your first task.</p><button class="btn primary" type="button" onclick="openTaskModal()">＋ Create task</button></div></td></tr><?php endif;?>
<?php foreach($tasks as $t): $isOverdue=($t['due_date'] && $t['due_date']<date('Y-m-d') && !in_array($t['status'],['Completed','Cancelled'],true)); $isToday=($t['due_date']===date('Y-m-d') && !in_array($t['status'],['Completed','Cancelled'],true)); $pClass='p-'.strtolower($t['priority']); $sClass='s-'.strtolower(str_replace(' ','-',$t['status'])); ?>
<tr><td><a class="task-title" href="?view=<?=(int)$t['id']?>"><?=h($t['title'])?></a><div class="task-desc"><?=h($t['description'] ?: $t['category'])?></div></td><td><span class="pill <?=h($pClass)?>"><?=h($t['priority'])?></span></td><td><span class="grade grade-<?=h($t['grade'])?>"><?=h($t['grade'])?></span></td><td><span class="pill <?=h($sClass)?>"><?=h($t['status'])?></span></td><td class="progress-cell"><div class="progress-line"><div class="track"><span style="width:<?=min(100,(int)$t['progress'])?>%;<?=((int)$t['progress']===100?'background:var(--green)':'')?>"></span></div><span class="progress-number"><?= (int)$t['progress']?>%</span></div><form class="inline-update" method="post" style="margin-top:8px"><input type="hidden" name="csrf" value="<?=h($csrf)?>"><input type="hidden" name="action" value="quick_update"><input type="hidden" name="id" value="<?=(int)$t['id']?>"><select name="status" aria-label="Update status"><?php foreach($statuses as $v):?><option <?=($t['status']===$v?'selected':'')?> value="<?=h($v)?>"><?=h($v)?></option><?php endforeach;?></select><select name="progress" aria-label="Update progress"><?php foreach([0,10,20,30,40,50,60,70,80,90,100] as $v):?><option <?=((int)$t['progress']===$v?'selected':'')?> value="<?=$v?>"><?=$v?>%</option><?php endforeach;?></select><button type="submit">Save</button></form></td><td><?=h($t['assignee'] ?: '—')?></td><td><span class="due <?=($isOverdue?'overdue':($isToday?'today':($t['status']==='Completed'?'done':'')))?>"><?php if(!$t['due_date']):?>No date<?php else:?><?=h(date('d M Y',strtotime($t['due_date'])))?><?php if($isOverdue):?> · Overdue<?php elseif($isToday):?> · Today<?php endif;?><?php endif;?></span></td><td><div class="actions"><a class="icon-btn" title="View details & notes" href="?view=<?=(int)$t['id']?>">↗</a><button class="icon-btn" title="Edit task" type="button" onclick='editTask(<?=json_encode($t,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP)?>)'>✎</button><form method="post" onsubmit="return confirm('Delete this task and its notes? This cannot be undone.')"><input type="hidden" name="csrf" value="<?=h($csrf)?>"><input type="hidden" name="action" value="delete_task"><input type="hidden" name="id" value="<?=(int)$t['id']?>"><button class="icon-btn" title="Delete task" type="submit">×</button></form></div></td></tr>
<?php endforeach;?></tbody></table></div>
<?php if($totalRows>0):?><div class="pagination"><span>Showing <?=number_format($offset+1)?>–<?=number_format(min($offset+$perPage,$totalRows))?> of <?=number_format($totalRows)?></span><div class="pages"><?php if($page>1):?><a href="?<?=h(http_build_query(array_merge($filterQuery,['page'=>$page-1])))?>">← Prev</a><?php endif;?><?php for($p=max(1,$page-2);$p<=min($totalPages,$page+2);$p++):?><a class="<?=($p===$page?'current':'')?>" href="?<?=h(http_build_query(array_merge($filterQuery,['page'=>$p])))?>"><?=$p?></a><?php endfor;?><?php if($page<$totalPages):?><a href="?<?=h(http_build_query(array_merge($filterQuery,['page'=>$page+1])))?>">Next →</a><?php endif;?></div></div><?php endif;?>
</section>
<?php endif; ?>
<div class="footer">THE DIVINE LANDS · TASK WORKSPACE · CORE PHP + MYSQL</div>
</div>

<!-- Create/Edit modal -->
<div class="modal-backdrop" id="taskModal" role="dialog" aria-modal="true" aria-labelledby="taskModalTitle"><div class="modal"><div class="modal-head"><div><h2 id="taskModalTitle">Create a task</h2><div style="font-size:12px;color:var(--muted);margin-top:4px">Capture the work, assign its importance, and set a deadline.</div></div><button class="close" type="button" onclick="closeTaskModal()">×</button></div><form method="post" class="modal-body" id="taskForm"><input type="hidden" name="csrf" value="<?=h($csrf)?>"><input type="hidden" name="action" value="save_task"><input type="hidden" name="id" id="f_id" value="0"><div class="form-grid">
<div class="field full"><label for="f_title">Task title *</label><input required maxlength="200" id="f_title" name="title" placeholder="e.g. Follow up with land owner"></div>
<div class="field full"><label for="f_description">Task description</label><textarea id="f_description" name="description" placeholder="What needs to be done? Add relevant context, expected outcome, or instructions."></textarea></div>
<div class="field"><label for="f_category">Category</label><select id="f_category" name="category"><?php foreach($categories as $v):?><option value="<?=h($v)?>"><?=h($v)?></option><?php endforeach;?></select></div>
<div class="field"><label for="f_priority">Priority</label><select id="f_priority" name="priority"><?php foreach($priorities as $v):?><option <?=($v==='Medium'?'selected':'')?> value="<?=h($v)?>"><?=h($v)?></option><?php endforeach;?></select></div>
<div class="field"><label for="f_grade">Business grade</label><select id="f_grade" name="grade"><?php foreach($grades as $v):?><option <?=($v==='B'?'selected':'')?> value="<?=h($v)?>">Grade <?=h($v)?><?=$v==='A'?' — highest business value':($v==='D'?' — lowest business value':'')?></option><?php endforeach;?></select></div>
<div class="field"><label for="f_status">Status</label><select id="f_status" name="status"><?php foreach($statuses as $v):?><option <?=($v==='To Do'?'selected':'')?> value="<?=h($v)?>"><?=h($v)?></option><?php endforeach;?></select></div>
<div class="field"><label for="f_progress">Progress (%)</label><input type="number" id="f_progress" name="progress" min="0" max="100" step="5" value="0"></div>
<div class="field"><label for="f_assignee">Assigned to</label><input id="f_assignee" name="assignee" placeholder="Team member name (optional)"></div>
<div class="field"><label for="f_created_by">Created by</label><input id="f_created_by" name="created_by" value="<?=h($_SESSION['username'] ?? $_SESSION['user_name'] ?? '')?>" placeholder="Your name (optional)"></div>
<div class="field"><label for="f_start_date">Start date</label><input type="date" id="f_start_date" name="start_date"></div>
<div class="field"><label for="f_due_date">Due date</label><input type="date" id="f_due_date" name="due_date"></div>
</div><div class="form-footer"><button type="button" class="btn secondary" onclick="closeTaskModal()">Cancel</button><button type="submit" class="btn primary">Save task</button></div></form></div></div>

<?php if($viewTask):?><div class="modal-backdrop open" id="detailModal" role="dialog" aria-modal="true" aria-labelledby="detailTitle"><div class="modal"><div class="modal-head"><div><div class="eyebrow">TASK DETAILS</div><h2 id="detailTitle"><?=h($viewTask['title'])?></h2></div><button class="close" type="button" onclick="location.href='<?=h($selfPage)?>'">×</button></div><div class="modal-body"><div class="details-grid"><div class="detail-box"><small>Priority</small><strong><span class="pill p-<?=h(strtolower($viewTask['priority']))?>"><?=h($viewTask['priority'])?></span></strong></div><div class="detail-box"><small>Business grade</small><strong><span class="grade grade-<?=h($viewTask['grade'])?>"><?=h($viewTask['grade'])?></span></strong></div><div class="detail-box"><small>Status</small><strong><?=h($viewTask['status'])?></strong></div><div class="detail-box"><small>Category</small><strong><?=h($viewTask['category'])?></strong></div><div class="detail-box"><small>Assigned to</small><strong><?=h($viewTask['assignee'] ?: 'Unassigned')?></strong></div><div class="detail-box"><small>Due date</small><strong><?=h($viewTask['due_date'] ? date('d M Y',strtotime($viewTask['due_date'])) : 'Not set')?></strong></div></div><div style="margin:16px 0"><div class="overview-head"><strong>Task progress</strong><span><?= (int)$viewTask['progress']?>%</span></div><div class="track"><span style="width:<?=min(100,(int)$viewTask['progress'])?>%"></span></div></div><h3 style="font-size:14px">Description</h3><div class="detail-description"><?=h($viewTask['description'] ?: 'No description added.')?></div><div class="notes"><h3>Notes & activity (<?=count($viewNotes)?>)</h3><?php foreach($viewNotes as $n):?><div class="note"><div class="note-meta"><?=h($n['created_by'] ?: 'Team member')?> · <?=h(date('d M Y, h:i A',strtotime($n['created_at'])))?></div><div class="note-text"><?=h($n['note'])?></div></div><?php endforeach;?><?php if(!$viewNotes):?><p style="color:var(--muted);font-size:13px">No notes yet. Add the first update below.</p><?php endif;?><form method="post" style="margin-top:14px"><input type="hidden" name="csrf" value="<?=h($csrf)?>"><input type="hidden" name="action" value="add_note"><input type="hidden" name="task_id" value="<?=(int)$viewTask['id']?>"><div class="field"><label for="note">Add a note or follow-up update</label><textarea required id="note" name="note" placeholder="Record a call, decision, blocker, next step, or important context…"></textarea></div><div class="form-grid" style="margin-top:10px"><div class="field"><label for="note_by">Added by</label><input id="note_by" name="created_by" value="<?=h($_SESSION['username'] ?? $_SESSION['user_name'] ?? '')?>" placeholder="Your name"></div><div class="field" style="justify-content:end"><button class="btn primary" type="submit">＋ Add note</button></div></div></form></div><div class="form-footer"><a class="btn secondary" href="<?=h($selfPage)?>">Back to tasks</a><button class="btn primary" type="button" onclick='editTask(<?=json_encode($viewTask,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP)?>)'>✎ Edit task</button></div></div></div></div><?php endif;?>
<script>
function openTaskModal(){document.getElementById('taskModal').classList.add('open');document.getElementById('taskModalTitle').textContent='Create a task';document.getElementById('taskForm').reset();document.getElementById('f_id').value='0';document.getElementById('f_priority').value='Medium';document.getElementById('f_grade').value='B';document.getElementById('f_status').value='To Do';document.getElementById('f_progress').value='0';setTimeout(()=>document.getElementById('f_title').focus(),80)}
function closeTaskModal(){document.getElementById('taskModal').classList.remove('open')}
function editTask(t){if(document.getElementById('detailModal')){document.getElementById('detailModal').classList.remove('open')}document.getElementById('taskForm').reset();document.getElementById('taskModalTitle').textContent='Edit task';document.getElementById('f_id').value=t.id||0;['title','description','category','priority','grade','status','progress','assignee','created_by','start_date','due_date'].forEach(k=>{let el=document.getElementById('f_'+k);if(el)el.value=t[k]??(k==='progress'?0:'')});document.getElementById('taskModal').classList.add('open');setTimeout(()=>document.getElementById('f_title').focus(),80)}
document.getElementById('taskModal').addEventListener('click',e=>{if(e.target.id==='taskModal')closeTaskModal()});document.addEventListener('keydown',e=>{if(e.key==='Escape')closeTaskModal()});
const statusEl=document.getElementById('f_status'), progressEl=document.getElementById('f_progress');statusEl.addEventListener('change',()=>{if(statusEl.value==='Completed')progressEl.value=100});progressEl.addEventListener('change',()=>{if(Number(progressEl.value)>=100&&statusEl.value!=='Completed')statusEl.value='In Review'});
function toggleDaysPicker(){const el=document.getElementById('daysPicker'),sel=document.getElementById('dailyRecurrence');if(el&&sel)el.style.display=sel.value==='Selected days'?'block':'none'}toggleDaysPicker();
</script>
</body></html>
<?php mysqli_close($con); ?>
