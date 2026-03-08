<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: landing-page.php");
    exit();
}
$fullname = $_SESSION['fullname'];
$user_slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $fullname)));

$conn = mysqli_connect("localhost", "root", "", "lending_db");
if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

// ── Handle Return Item (AJAX) ──────────────────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'return_item') {
    header('Content-Type: application/json');
    if (!isset($_SESSION['user_id'])) { echo json_encode(['success'=>false,'msg'=>'Unauthorized']); exit; }
    $req_id = intval($_POST['request_id'] ?? 0);
    $uid_r  = mysqli_real_escape_string($conn, $_SESSION['user_id']);
    // Fetch the request (verify ownership)
    $rq = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM tbl_requests WHERE id=$req_id AND student_id='$uid_r' LIMIT 1"));
    if (!$rq) { echo json_encode(['success'=>false,'msg'=>'Request not found']); exit; }
    if (!in_array($rq['status'], ['Approved','Overdue'])) { echo json_encode(['success'=>false,'msg'=>'Cannot return this item']); exit; }
    // Mark as Returned
    mysqli_query($conn, "UPDATE tbl_requests SET status='Returned' WHERE id=$req_id");
    // Increment inventory quantity
    $eq_name = mysqli_real_escape_string($conn, $rq['equipment_name']);
    mysqli_query($conn, "UPDATE tbl_inventory SET quantity = quantity + 1 WHERE item_name='$eq_name' LIMIT 1");
    echo json_encode(['success'=>true,'msg'=>'Item returned successfully!']);
    exit;
}

// ── Auto-decline expired requests ──────────────────────────────────────────
$today = date('Y-m-d');
$reason_expired = "Request expired – borrow date has already passed";
$stmt_expired = $conn->prepare("UPDATE tbl_requests SET status='Declined', reason=? WHERE status='Waiting' AND borrow_date < ?");
$stmt_expired->bind_param("ss", $reason_expired, $today);
$stmt_expired->execute();

// ── Auto-mark overdue approved requests ────────────────────────────────────
mysqli_query($conn, "UPDATE tbl_requests SET status='Overdue' WHERE status='Approved' AND return_date < '$today'");

// ── Handle Borrow Request ──────────────────────────────────────────────────
if (isset($_POST['borrow_submit']) || isset($_POST['equipment_name'])) {
    if (!isset($_SESSION['user_id'])) die("Unauthorized access");

    $user_id = $_SESSION['user_id'];
    $user_query = mysqli_query($conn, "SELECT fullname, student_id FROM tbl_users WHERE student_id='" . mysqli_real_escape_string($conn, $user_id) . "'");
    $user = mysqli_fetch_assoc($user_query);
    if (!$user) die("User profile not found.");

    $student_name   = $user['fullname'];
    $student_id     = $user['student_id'];
    $borrow_date    = mysqli_real_escape_string($conn, $_POST['borrow_date']);
    $return_date    = mysqli_real_escape_string($conn, $_POST['return_date']);
    $equipment_name = mysqli_real_escape_string($conn, $_POST['equipment_name']);
    $room           = mysqli_real_escape_string($conn, $_POST['room']);
    $instructor     = preg_replace("/[^a-zA-Z\s.']/", "", $_POST['instructor']);
    $instructor     = mysqli_real_escape_string($conn, trim($instructor));
    $current_date   = date('Y-m-d');

    if ($borrow_date < $current_date) die("Error: You cannot select a borrow date in the past.");
    if ($return_date < $borrow_date)  die("Error: Return date cannot be before the borrow date.");

    $insert = "INSERT INTO tbl_requests (student_name,student_id,equipment_name,instructor,room,borrow_date,return_date,status,request_date)
               VALUES ('$student_name','$student_id','$equipment_name','$instructor','$room','$borrow_date','$return_date','Waiting',NOW())";
    if (mysqli_query($conn, $insert)) {
        header("Location: user-dashboard.php?success=1");
        exit();
    } else {
        die("Error processing request: " . mysqli_error($conn));
    }
}

// ── Inventory & Requests ───────────────────────────────────────────────────
// only show non-archived rows to regular users so they cannot borrow retired items
$category_result  = mysqli_query($conn, "SELECT DISTINCT category FROM tbl_inventory WHERE is_archived = 0 ORDER BY category ASC");
$inventory_result = mysqli_query($conn, "SELECT * FROM tbl_inventory WHERE is_archived = 0 ORDER BY item_name ASC");
$my_requests_result = null;
if (isset($_SESSION['user_id'])) {
    $uid = $_SESSION['user_id'];
    $my_requests_result = mysqli_query($conn, "SELECT * FROM tbl_requests WHERE student_id='$uid' ORDER BY request_date DESC");
}

// ── Avatar initials ────────────────────────────────────────────────────────
$name_parts = explode(' ', trim($fullname));
$firstname  = $name_parts[0];
$initials   = strtoupper(substr($name_parts[0], 0, 1));
if (count($name_parts) > 1) $initials .= strtoupper(substr(end($name_parts), 0, 1));

// ── Stats for Home tab ─────────────────────────────────────────────────────
$uid_safe = mysqli_real_escape_string($conn, $_SESSION['user_id']);
$stat_total    = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM tbl_requests WHERE student_id='$uid_safe'"))['c'];
$stat_waiting  = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM tbl_requests WHERE student_id='$uid_safe' AND status='Waiting'"))['c'];
$stat_approved = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM tbl_requests WHERE student_id='$uid_safe' AND status='Approved'"))['c'];
$stat_declined = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM tbl_requests WHERE student_id='$uid_safe' AND status='Declined'"))['c'];
$stat_overdue  = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM tbl_requests WHERE student_id='$uid_safe' AND status='Overdue'"))['c'];

// Build requests array for JS
$requests_raw = mysqli_query($conn, "SELECT * FROM tbl_requests WHERE student_id='$uid_safe' ORDER BY request_date DESC");
$requests_js = [];
while ($row = mysqli_fetch_assoc($requests_raw)) {
    $requests_js[] = $row;
}
$requests_json = json_encode($requests_js, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

// Overdue items for notifications
$overdue_items_raw = mysqli_query($conn, "SELECT * FROM tbl_requests WHERE student_id='$uid_safe' AND status='Overdue' ORDER BY return_date ASC");
$overdue_notifs = [];
while ($row = mysqli_fetch_assoc($overdue_items_raw)) {
    $overdue_notifs[] = $row;
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PUP Sync | User Portal</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">

    <link rel="stylesheet" href="css/user-dashboard.css">

</head>

<body>

    <!-- ================================================================
     HEADER
================================================================ -->
    <header class="app-header">
        <div class="header-left">
            <div class="app-logo">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="logo-icon" style="color: var(--accent-maroon)" aria-label="PUPSYNC" aria-hidden="true">
                    <polygon points="12 2 2 7 12 12 22 7 12 2" />
                    <polyline points="2 17 12 22 22 17" />
                    <polyline points="2 12 12 17 22 12" />
                </svg>
                <div class="app-logo-text" style="display: flex; flex-direction: column;">
                    <span style="white-space: nowrap; line-height: 1.1;">
                        <strong style="font-size: 25px;">PUP</strong><span style="font-weight: 500; letter-spacing: -0.3px; font-size: 21px; vertical-align: baseline; margin-left: 1px;">SYNC</span>
                    </span>
                    <small>User Portal</small>
                </div>
            </div>
        </div>

        <!-- Center: Top Navigation Tabs -->
        <nav class="nav-tabs-wrap" role="navigation" aria-label="Main Navigation">
            <button class="nav-tab active" id="tab-home" data-tab="home">
                Home
            </button>
            <button class="nav-tab" id="tab-lending" data-tab="lending">
                Lending
            </button>
            <button class="nav-tab" id="tab-rooms" data-tab="rooms">
                Rooms
            </button>
        </nav>

        <div class="header-right">
            <div class="header-user-info">
                <span class="u-name"><?php echo htmlspecialchars($fullname); ?></span>
                <span class="u-id">ID: <?php echo htmlspecialchars($_SESSION['user_id']); ?></span>
            </div>

            <div class="avatar-btn" id="avatarBtn" title="Account menu"
                role="button" aria-haspopup="true" aria-expanded="false">
                <?php echo htmlspecialchars($initials); ?>
            </div>

            <!-- Profile Dropdown -->
            <div class="profile-dropdown" id="profileDropdown" role="menu">
                <div class="dd-header">
                    <div class="dd-avatar"><?php echo htmlspecialchars($initials); ?></div>
                    <div>
                        <span class="dd-name"><?php echo htmlspecialchars($fullname); ?></span>
                        <span class="dd-sub">ID: <?php echo htmlspecialchars($_SESSION['user_id']); ?></span>
                        <span class="dd-sub" style="margin-top:2px;">Student</span>
                    </div>
                </div>
                <div class="dd-menu">
                    <button class="dd-item" data-action="open-overlay" data-target="accountOverlay">
                        Account
                    </button>
                    <button class="dd-item" data-action="open-overlay" data-target="notifOverlay">
                        Notifications
                        <span class="notif-badge" id="notifBadge"><?php echo (3 + count($overdue_notifs)); ?></span>
                    </button>
                    <button class="dd-item" data-action="open-overlay" data-target="settingsOverlay">
                        Settings
                    </button>
                    <div class="dd-divider"></div>
                    <button class="dd-item dd-logout" data-action="logout">
                        <div class="dd-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16" style="width:16px;height:16px;" aria-label="Logout" aria-hidden="true">
                                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
                                <polyline points="16 17 21 12 16 7" />
                                <line x1="21" y1="12" x2="9" y2="12" />
                            </svg></div> Logout
                    </button>
                </div>
            </div>
        </div>
    </header>


    <!-- ================================================================
     MAIN CONTENT
================================================================ -->
    <main id="app-main">

        <!-- Success Alert -->
        <?php if (isset($_GET['success'])): ?>
            <div class="alert-banner alert-success" id="success-alert">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon-img" aria-label="Success" aria-hidden="true">
                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" />
                    <polyline points="22 4 12 14.01 9 11.01" />
                </svg>
                <strong>Success!</strong> Your borrow request has been submitted for approval.
                <button class="alert-close" data-action="dismiss-alert" data-target="success-alert">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16" style="width:16px;height:16px;" aria-label="Close" aria-hidden="true">
                        <line x1="18" y1="6" x2="6" y2="18" />
                        <line x1="6" y1="6" x2="18" y2="18" />
                    </svg>
                </button>
            </div>
        <?php endif; ?>

        <!-- Overdue Alert -->
        <div class="alert-banner alert-danger hidden" id="overdue-alert">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon-img" aria-label="Alert" aria-hidden="true">
                <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" />
                <line x1="12" y1="9" x2="12" y2="13" />
                <line x1="12" y1="17" x2="12.01" y2="17" />
            </svg>
            <strong>Overdue Alert:</strong> You have overdue equipment — please return it immediately!
            <button class="alert-close" data-action="dismiss-alert" data-target="overdue-alert">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16" style="width:16px;height:16px;" aria-label="Close" aria-hidden="true">
                    <line x1="18" y1="6" x2="6" y2="18" />
                    <line x1="6" y1="6" x2="18" y2="18" />
                </svg>
            </button>
        </div>

        <!-- ============================================================
         TAB: HOME
    ============================================================ -->
        <div class="tab-panel active" id="panel-home">
            <div class="section-header">
                <h2><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="important-icon" aria-label="Home" aria-hidden="true">
                        <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" />
                        <polyline points="9 22 9 12 15 12 15 22" />
                    </svg>Welcome back, <?php echo htmlspecialchars($firstname); ?>! 👋</h2>
                <p><?php echo date('l, F j, Y'); ?> &mdash; Here's a summary of your activity.</p>
            </div>

            <!-- Hero Card -->
            <div class="hero-card">
                <h1>Equipment Lending Portal</h1>
                <p>Browse available equipment, submit borrow requests, and track your approvals — all in one place.</p>
            </div>

            <!-- Stats -->
            <p style="font-size:0.72rem; font-weight:700; text-transform:uppercase; letter-spacing:1.2px; color:var(--text-light); margin-bottom:0.8rem;">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="important-icon" aria-label="Activity" aria-hidden="true">
                    <line x1="18" y1="20" x2="18" y2="10" />
                    <line x1="12" y1="20" x2="12" y2="4" />
                    <line x1="6" y1="20" x2="6" y2="14" />
                    <line x1="2" y1="20" x2="22" y2="20" />
                </svg>Your Activity
            </p>
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="20" height="20" style="width:20px;height:20px;" aria-label="Total" aria-hidden="true">
                            <polygon points="12 2 2 7 12 12 22 7 12 2" />
                            <polyline points="2 17 12 22 22 17" />
                            <polyline points="2 12 12 17 22 12" />
                        </svg></div>
                    <div class="stat-label">Total Requests</div>
                    <div class="stat-value"><?php echo $stat_total; ?></div>
                    <div class="stat-sub">All time</div>
                </div>
                <div class="stat-card stat-card-clickable" data-action="filter-requests" data-status="Waiting" title="View Pending requests">
                    <div class="stat-icon" style="background:#fff8e1; color:#c67c00;"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="20" height="20" style="width:20px;height:20px;" aria-label="Pending" aria-hidden="true">
                            <circle cx="12" cy="12" r="10" />
                            <polyline points="12 6 12 12 16 14" />
                        </svg></div>
                    <div class="stat-label">Pending</div>
                    <div class="stat-value" style="color:var(--warning);"><?php echo $stat_waiting; ?></div>
                    <div class="stat-sub stat-sub-link">Awaiting approval →</div>
                </div>
                <div class="stat-card stat-card-clickable" data-action="filter-requests" data-status="Approved" title="View Approved requests">
                    <div class="stat-icon" style="background:#e3fcef; color:#00875a;"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="20" height="20" style="width:20px;height:20px;" aria-label="Approved" aria-hidden="true">
                            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" />
                            <polyline points="22 4 12 14.01 9 11.01" />
                        </svg></div>
                    <div class="stat-label">Approved</div>
                    <div class="stat-value" style="color:var(--success);"><?php echo $stat_approved; ?></div>
                    <div class="stat-sub stat-sub-link">Ready to pick up →</div>
                </div>
                <div class="stat-card stat-card-clickable" data-action="filter-requests" data-status="Declined" title="View Declined requests">
                    <div class="stat-icon" style="background:#ffeaea; color:var(--danger);"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="20" height="20" style="width:20px;height:20px;" aria-label="Declined" aria-hidden="true">
                            <line x1="18" y1="6" x2="6" y2="18" />
                            <line x1="6" y1="6" x2="18" y2="18" />
                        </svg></div>
                    <div class="stat-label">Declined</div>
                    <div class="stat-value" style="color:var(--danger);"><?php echo $stat_declined; ?></div>
                    <div class="stat-sub stat-sub-link">Review reasons →</div>
                </div>
                <div class="stat-card stat-card-clickable<?php echo $stat_overdue > 0 ? ' stat-card-overdue' : ''; ?>" data-action="filter-requests" data-status="Overdue" title="View Overdue items">
                    <div class="stat-icon" style="background:#fff3e0; color:#e65100;"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="20" height="20" style="width:20px;height:20px;" aria-label="Overdue" aria-hidden="true">
                            <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" />
                            <line x1="12" y1="9" x2="12" y2="13" />
                            <line x1="12" y1="17" x2="12.01" y2="17" />
                        </svg></div>
                    <div class="stat-label">Overdue</div>
                    <div class="stat-value" style="color:#e65100;" id="statOverdueVal"><?php echo $stat_overdue; ?></div>
                    <div class="stat-sub stat-sub-link">Items past due →</div>
                </div>
            </div>

            <div class="home-grid">
                <!-- Events -->
                <div class="event-container">
                    <h3><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon-img" style="color:var(--accent-maroon); margin-right:8px" aria-label="Calendar" aria-hidden="true">
                            <rect x="3" y="4" width="18" height="18" rx="2" ry="2" />
                            <line x1="16" y1="2" x2="16" y2="6" />
                            <line x1="8" y1="2" x2="8" y2="6" />
                            <line x1="3" y1="10" x2="21" y2="10" />
                        </svg>Upcoming Events</h3>
                    <div class="event-item">
                        <div class="date-badge"><span>17</span><small>Feb</small></div>
                        <div class="event-info">
                            <h4>Lab Equipment Audit</h4>
                            <p>Annual inventory check &mdash; Admin Office, 8 AM</p>
                            <span class="status-tag tag-ongoing">Ongoing</span>
                        </div>
                    </div>
                    <div class="event-item">
                        <div class="date-badge"><span>21</span><small>Feb</small></div>
                        <div class="event-info">
                            <h4>BSIT Capstone Defense</h4>
                            <p>Room 301 &mdash; All equipment must be returned by 7 AM</p>
                            <span class="status-tag tag-upcoming">Upcoming</span>
                        </div>
                    </div>
                    <div class="event-item">
                        <div class="date-badge"><span>28</span><small>Feb</small></div>
                        <div class="event-info">
                            <h4>System Maintenance</h4>
                            <p>Portal offline 11 PM – 1 AM for updates</p>
                            <span class="status-tag tag-upcoming">Upcoming</span>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="quick-actions">
                    <h3><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon-img" style="color:var(--accent-maroon); margin-right:8px" aria-label="Quick" aria-hidden="true">
                            <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2" />
                        </svg>Quick Actions</h3>
                    <button class="qa-btn" data-action="go-tab" data-tab="lending" data-lending="browse">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon-img" aria-label="Search" aria-hidden="true">
                            <circle cx="11" cy="11" r="8" />
                            <line x1="21" y1="21" x2="16.65" y2="16.65" />
                        </svg> Browse Equipment
                    </button>
                    <button class="qa-btn" data-action="go-tab" data-tab="lending" data-lending="requests">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon-img" aria-label="Requests" aria-hidden="true">
                            <path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2" />
                            <rect x="8" y="2" width="8" height="4" rx="1" ry="1" />
                        </svg> My Requests
                    </button>
                    <button class="qa-btn" data-action="go-tab" data-tab="rooms">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon-img" aria-label="Rooms" aria-hidden="true">
                            <rect x="3" y="3" width="18" height="18" rx="2" ry="2" />
                            <line x1="9" y1="3" x2="9" y2="21" />
                            <circle cx="6" cy="12" r="1" fill="currentColor" stroke="none" />
                        </svg> Reserve a Room
                    </button>
                    <button class="qa-btn" data-action="open-overlay" data-target="notifOverlay">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon-img" aria-label="Notifications" aria-hidden="true">
                            <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9" />
                            <path d="M13.73 21a2 2 0 0 1-3.46 0" />
                        </svg> Notifications <span class="notif-badge" style="font-size:0.7rem; padding: 1px 6px;"><?php echo (3 + count($overdue_notifs)); ?></span>
                    </button>
                </div>
            </div>
        </div><!-- /panel-home -->


        <!-- ============================================================
         TAB: LENDING
    ============================================================ -->
        <div class="tab-panel" id="panel-lending">

            <!-- Lending Sub-Nav -->
            <div class="lending-nav">
                <button class="lending-nav-btn active" data-lending-nav="browse">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon-img" aria-label="Browse" aria-hidden="true">
                        <circle cx="11" cy="11" r="8" />
                        <line x1="21" y1="21" x2="16.65" y2="16.65" />
                    </svg> Browse Equipment
                </button>
                <button class="lending-nav-btn" data-lending-nav="requests">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon-img" aria-label="Requests" aria-hidden="true">
                        <path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2" />
                        <rect x="8" y="2" width="8" height="4" rx="1" ry="1" />
                    </svg> My Requests
                </button>
            </div>

            <!-- ── Sub: Browse Equipment ─────────────────────────── -->
            <div class="lending-sub active" id="lending-browse">
                <div class="page-header">
                    <h2><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="important-icon" aria-label="Browse equipment" aria-hidden="true">
                            <circle cx="11" cy="11" r="8" />
                            <line x1="21" y1="21" x2="16.65" y2="16.65" />
                        </svg>Browse Equipment</h2>
                    <p>Search and request available school equipment for academic use.</p>
                </div>

                <div class="eq-card">
                    <div class="eq-card-body">
                        <div class="filter-row">
                            <div class="search-wrap">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="search-icon" aria-label="Search" aria-hidden="true">
                                    <circle cx="11" cy="11" r="8" />
                                    <line x1="21" y1="21" x2="16.65" y2="16.65" />
                                </svg>
                                <input type="text" id="equipmentSearch" placeholder="Search by equipment name...">
                            </div>
                            <select id="categoryFilter" class="filter-select">
                                <option value="">All Categories</option>
                                <?php
                                mysqli_data_seek($category_result, 0);
                                while ($cat = mysqli_fetch_assoc($category_result)) {
                                    if (strtolower($cat['category']) === 'others') continue;
                                    echo '<option value="' . htmlspecialchars($cat['category']) . '">' . htmlspecialchars($cat['category']) . '</option>';
                                }
                                ?>
                                <option value="Others">Others</option>
                            </select>
                        </div>

                        <div class="eq-grid" id="equipmentList">
                            <?php if (mysqli_num_rows($inventory_result) == 0): ?>
                                <div style="grid-column:1/-1; text-align:center; padding:3rem; color:var(--text-light);">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="48" height="48" style="width:48px;height:48px;color:var(--khaki-border);display:block;margin-bottom:0.8rem;opacity:0.7;" aria-label="No items" aria-hidden="true">
                                        <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z" />
                                        <polyline points="3.27 6.96 12 12.01 20.73 6.96" />
                                        <line x1="12" y1="22.08" x2="12" y2="12" />
                                    </svg>
                                    No equipment available at the moment.
                                </div>
                            <?php else: ?>
                                <?php while ($item = mysqli_fetch_assoc($inventory_result)): ?>
                                    <div class="eq-item-card item-node"
                                        data-name="<?php echo strtolower(htmlspecialchars($item['item_name'])); ?>"
                                        data-category="<?php echo strtolower(htmlspecialchars($item['category'])); ?>">

                                        <?php if (!empty($item['image_path'])): ?>
                                            <img class="eq-item-img"
                                                src="/Equipment-Lending-Website/<?php echo htmlspecialchars($item['image_path']); ?>"
                                                alt="<?php echo htmlspecialchars($item['item_name']); ?>">
                                        <?php else: ?>
                                            <div class="eq-item-img-placeholder">
                                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="36" height="36" style="width:36px;height:36px;" aria-label="Item" aria-hidden="true">
                                                    <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z" />
                                                    <polyline points="3.27 6.96 12 12.01 20.73 6.96" />
                                                    <line x1="12" y1="22.08" x2="12" y2="12" />
                                                </svg>
                                            </div>
                                        <?php endif; ?>

                                        <div class="eq-item-body">
                                            <div class="eq-item-name"><?php echo htmlspecialchars($item['item_name']); ?></div>
                                            <div class="eq-item-meta">
                                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14" style="width:14px;height:14px;" aria-label="Category" aria-hidden="true">
                                                    <path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z" />
                                                    <line x1="7" y1="7" x2="7.01" y2="7" />
                                                </svg>
                                                <?php echo htmlspecialchars($item['category']); ?>
                                            </div>
                                            <div style="margin-bottom:6px;">
                                                <?php if ($item['quantity'] > 0): ?>
                                                    <span class="stock-badge stock-avail">
                                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="12" height="12" style="width:12px;height:12px;" aria-label="Available" aria-hidden="true">
                                                            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" />
                                                            <polyline points="22 4 12 14.01 9 11.01" />
                                                        </svg>
                                                        <?php echo (int)$item['quantity']; ?> available
                                                    </span>
                                                <?php else: ?>
                                                    <span class="stock-badge stock-unavail">
                                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="12" height="12" style="width:12px;height:12px;" aria-label="Unavailable" aria-hidden="true">
                                                            <line x1="18" y1="6" x2="6" y2="18" />
                                                            <line x1="6" y1="6" x2="18" y2="18" />
                                                        </svg>
                                                        Out of stock
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            <button class="btn-borrow"
                                                <?php if ($item['quantity'] <= 0) echo 'disabled'; ?>
                                                data-action="open-borrow-form"
                                                data-item="<?php echo htmlspecialchars($item['item_name'], ENT_QUOTES); ?>">
                                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14" style="width:14px;height:14px;" aria-label="Borrow" aria-hidden="true">
                                                    <path d="M18 11V6a2 2 0 0 0-2-2 2 2 0 0 0-2 2" />
                                                    <path d="M14 10V4a2 2 0 0 0-2-2 2 2 0 0 0-2 2v2" />
                                                    <path d="M10 10.5V6a2 2 0 0 0-2-2 2 2 0 0 0-2 2v8" />
                                                    <path d="M18 8a2 2 0 1 1 4 0v6a8 8 0 0 1-8 8h-2c-2.8 0-4.5-.86-5.99-2.34l-3.6-3.6a2 2 0 0 1 2.83-2.82L7 15" />
                                                </svg>
                                                <?php echo ($item['quantity'] > 0) ? 'Borrow' : 'Unavailable'; ?>
                                            </button>
                                        </div>
                                    </div>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div><!-- /lending-browse -->

            <!-- ── Sub: Borrow Form ──────────────────────────────── -->
            <div class="lending-sub" id="lending-form">
                <div class="page-header">
                    <h2>Borrow Request</h2>
                    <p>Fill in the details below to submit your borrowing request.</p>
                </div>

                <div class="eq-card form-card">
                    <div class="form-card-header">
                        <h2>Borrowing Form</h2>
                        <button class="btn-close-custom" data-action="lending-back" title="Go back">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16" style="width:16px;height:16px;" aria-label="Close" aria-hidden="true">
                                <line x1="18" y1="6" x2="6" y2="18" />
                                <line x1="6" y1="6" x2="18" y2="18" />
                            </svg>
                        </button>
                    </div>
                    <div class="form-card-body">
                        <div class="selected-item-banner" id="selectedItemBanner">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="18" height="18" style="width:18px;height:18px;" aria-label="Selected" aria-hidden="true">
                                <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z" />
                                <polyline points="3.27 6.96 12 12.01 20.73 6.96" />
                                <line x1="12" y1="22.08" x2="12" y2="12" />
                            </svg>
                            <span id="selectedItemLabel">No item selected</span>
                        </div>

                        <form id="borrowForm" method="POST" action="">
                            <input type="hidden" name="equipment_name" id="selectedItem">

                            <div class="form-group">
                                <label>Instructor</label>
                                <input type="text" name="instructor" id="instructorField"
                                    class="form-control-custom" placeholder="e.g. Sir. Migs" required>
                            </div>
                            <div class="form-group">
                                <label>Room / Laboratory</label>
                                <input type="text" name="room" class="form-control-custom"
                                    placeholder="e.g. Lab 301" required>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Borrow Date</label>
                                    <input type="date" name="borrow_date" id="borrow_date" class="form-control-custom" required>
                                </div>
                                <div class="form-group">
                                    <label>Return Date</label>
                                    <input type="date" name="return_date" id="return_date" class="form-control-custom" required>
                                </div>
                            </div>
                            <button type="submit" class="btn-submit-form">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16" style="width:16px;height:16px;" aria-label="Send" aria-hidden="true">
                                    <line x1="22" y1="2" x2="11" y2="13" />
                                    <polygon points="22 2 15 22 11 13 2 9 22 2" />
                                </svg> Submit Borrow Request
                            </button>
                        </form>
                    </div>
                </div>
            </div><!-- /lending-form -->

            <!-- ── Sub: My Requests ──────────────────────────────── -->
            <div class="lending-sub" id="lending-requests">
                <div class="page-header">
                    <h2><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="important-icon" aria-label="Requests" aria-hidden="true">
                            <path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2" />
                            <rect x="8" y="2" width="8" height="4" rx="1" ry="1" />
                        </svg>My Borrow Requests</h2>
                    <p>Track the status of all your submitted borrow requests.</p>
                </div>

                <div class="eq-card">
                    <div class="eq-card-header" style="flex-wrap:wrap; gap:10px;">
                        <h2>Request History</h2>
                        <div class="requests-toolbar">
                            <!-- Status Filter -->
                            <div class="req-filter-wrap">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14" style="width:14px;height:14px;color:var(--text-light);" aria-hidden="true">
                                    <polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/>
                                </svg>
                                <select id="reqStatusFilter" class="req-filter-select" data-action="filter-requests-dd">
                                    <option value="All">All Statuses</option>
                                    <option value="Waiting">Pending</option>
                                    <option value="Approved">Approved</option>
                                    <option value="Declined">Declined</option>
                                    <option value="Overdue">Overdue</option>
                                    <option value="Returned">Returned</option>
                                </select>
                            </div>
                            <!-- Sort Order -->
                            <button class="req-sort-btn" id="reqSortBtn" data-action="toggle-sort" title="Toggle sort order">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14" style="width:14px;height:14px;" aria-hidden="true">
                                    <line x1="12" y1="5" x2="12" y2="19"/><polyline points="5 12 12 5 19 12"/>
                                </svg>
                                <span id="reqSortLabel">Latest First</span>
                            </button>
                        </div>
                    </div>
                    <div style="overflow-x:auto;">
                        <table class="requests-table" id="requestsTable">
                            <thead>
                                <tr>
                                    <th><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon-img" aria-hidden="true"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>Equipment</th>
                                    <th><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon-img" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>Instructor</th>
                                    <th><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon-img" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="9" y1="3" x2="9" y2="21"/><circle cx="6" cy="12" r="1" fill="currentColor" stroke="none"/></svg>Room</th>
                                    <th><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon-img" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>Borrow Date</th>
                                    <th><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon-img" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>Return Date</th>
                                    <th>Status</th>
                                    <th>Reason / Notes</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody id="requestsTbody">
                                <!-- Populated by JS from PHP JSON -->
                            </tbody>
                        </table>
                    </div>
                </div>
            </div><!-- /lending-requests -->

            <!-- Embed requests data for JS -->
            <script>
                window.REQUESTS_DATA = <?php echo $requests_json; ?>;
            </script>

        </div><!-- /panel-lending -->


        <!-- ============================================================
         TAB: ROOMS
    ============================================================ -->
        <div class="tab-panel" id="panel-rooms">
            <div class="section-header">
                <h2><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="important-icon" aria-label="Rooms" aria-hidden="true">
                        <rect x="3" y="3" width="18" height="18" rx="2" ry="2" />
                        <line x1="9" y1="3" x2="9" y2="21" />
                        <circle cx="6" cy="12" r="1" fill="currentColor" stroke="none" />
                    </svg>Room Reservation</h2>
                <p>Browse available rooms and make a reservation for your class or event.</p>
            </div>

            <!-- Coming Soon Banner -->
            <div class="coming-soon-banner">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon-img" aria-label="Coming soon" aria-hidden="true">
                    <circle cx="12" cy="12" r="10" />
                    <polyline points="12 6 12 12 16 14" />
                </svg>
                <h3>Room Reservation — Coming Soon</h3>
                <p>This feature is under development. You can preview available rooms below and fill a reservation form when it launches.</p>
            </div>

            <!-- Room Cards (Pseudo Design) -->
            <div class="room-list" id="roomList">
                <!-- Room 1 -->
                <div class="room-card">
                    <div class="room-img"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="48" height="48" style="width:48px;height:48px;" aria-label="Lab" aria-hidden="true">
                            <rect x="2" y="3" width="20" height="14" rx="2" ry="2" />
                            <line x1="8" y1="21" x2="16" y2="21" />
                            <line x1="12" y1="17" x2="12" y2="21" />
                        </svg></div>
                    <div class="room-info">
                        <div>
                            <div class="room-header">
                                <div>
                                    <h3>Computer Laboratory 301</h3>
                                    <p>3rd Floor, Main Building</p>
                                </div>
                                <span class="capacity-badge"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14" style="width:14px;height:14px;margin-right:6px;vertical-align:middle;" aria-label="Seats" aria-hidden="true">
                                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" />
                                        <circle cx="9" cy="7" r="4" />
                                        <path d="M23 21v-2a4 4 0 0 0-3-3.87" />
                                        <path d="M16 3.13a4 4 0 0 1 0 7.75" />
                                    </svg>40 seats</span>
                            </div>
                            <div class="amenities" style="margin-top:10px;">
                                <span><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14" style="width:14px;height:14px;margin-right:6px;vertical-align:middle;" aria-label="WiFi" aria-hidden="true">
                                        <path d="M1.42 9a16 16 0 0 1 21.16 0" />
                                        <path d="M5 12.55a11 11 0 0 1 14.08 0" />
                                        <path d="M8.53 16.11a6 6 0 0 1 6.95 0" />
                                        <line x1="12" y1="20" x2="12.01" y2="20" />
                                    </svg> WiFi</span>
                                <span><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14" style="width:14px;height:14px;margin-right:6px;vertical-align:middle;" aria-label="A/C" aria-hidden="true">
                                        <path d="M17.7 7.7a2.5 2.5 0 1 1 1.8 4.3H2" />
                                        <path d="M9.6 4.6A2 2 0 1 1 11 8H2" />
                                        <path d="M12.6 19.4A2 2 0 1 0 14 16H2" />
                                    </svg> A/C</span>
                                <span><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14" style="width:14px;height:14px;margin-right:6px;vertical-align:middle;" aria-label="Projector" aria-hidden="true">
                                        <rect x="2" y="7" width="20" height="15" rx="2" ry="2" />
                                        <polyline points="17 2 12 7 7 2" />
                                    </svg> Projector</span>
                            </div>
                        </div>
                        <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;">
                            <span class="room-avail"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 8 8" width="6" height="6" aria-hidden="true" style="vertical-align:middle;margin-right:6px;opacity:0.85;">
                                    <circle cx="4" cy="4" r="4" fill="currentColor" />
                                </svg> Available</span>
                            <button class="btn-borrow" style="width:auto; padding:9px 24px;"
                                data-action="open-room-form" data-room="Computer Laboratory 301">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14" style="width:14px;height:14px;margin-right:6px;vertical-align:middle;" aria-label="Reserve" aria-hidden="true">
                                    <rect x="3" y="4" width="18" height="18" rx="2" ry="2" />
                                    <line x1="16" y1="2" x2="16" y2="6" />
                                    <line x1="8" y1="2" x2="8" y2="6" />
                                    <line x1="3" y1="10" x2="21" y2="10" />
                                    <line x1="12" y1="15" x2="12" y2="19" />
                                    <line x1="10" y1="17" x2="14" y2="17" />
                                </svg> Reserve
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Room 2 -->
                <div class="room-card">
                    <div class="room-img"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="48" height="48" style="width:48px;height:48px;" aria-label="Lab" aria-hidden="true">
                            <path d="M9 3h6" />
                            <path d="M10 3v7l-5 11h14L14 10V3" />
                        </svg></div>
                    <div class="room-info">
                        <div>
                            <div class="room-header">
                                <div>
                                    <h3>Science Laboratory</h3>
                                    <p>2nd Floor, Science Wing</p>
                                </div>
                                <span class="capacity-badge"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14" style="width:14px;height:14px;margin-right:6px;vertical-align:middle;" aria-label="Seats" aria-hidden="true">
                                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" />
                                        <circle cx="9" cy="7" r="4" />
                                        <path d="M23 21v-2a4 4 0 0 0-3-3.87" />
                                        <path d="M16 3.13a4 4 0 0 1 0 7.75" />
                                    </svg>30 seats</span>
                            </div>
                            <div class="amenities" style="margin-top:10px;">
                                <span><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14" style="width:14px;height:14px;margin-right:6px;vertical-align:middle;" aria-label="A/C" aria-hidden="true">
                                        <path d="M17.7 7.7a2.5 2.5 0 1 1 1.8 4.3H2" />
                                        <path d="M9.6 4.6A2 2 0 1 1 11 8H2" />
                                        <path d="M12.6 19.4A2 2 0 1 0 14 16H2" />
                                    </svg> A/C</span>
                                <span><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14" style="width:14px;height:14px;margin-right:6px;vertical-align:middle;" aria-label="Water" aria-hidden="true">
                                        <path d="M12 2.69l5.66 5.66a8 8 0 1 1-11.31 0z" />
                                    </svg> Running Water</span>
                                <span><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14" style="width:14px;height:14px;margin-right:6px;vertical-align:middle;" aria-label="Safety" aria-hidden="true">
                                        <path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z" />
                                    </svg> Safety Kit</span>
                            </div>
                        </div>
                        <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;">
                            <span class="room-occupied"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 8 8" width="6" height="6" aria-hidden="true" style="vertical-align:middle;margin-right:6px;opacity:0.85;">
                                    <circle cx="4" cy="4" r="4" fill="currentColor" />
                                </svg> Occupied until 3 PM</span>
                            <button class="btn-borrow" style="width:auto; padding:9px 24px;"
                                data-action="open-room-form" data-room="Science Laboratory">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14" style="width:14px;height:14px;margin-right:6px;vertical-align:middle;" aria-label="Reserve" aria-hidden="true">
                                    <rect x="3" y="4" width="18" height="18" rx="2" ry="2" />
                                    <line x1="16" y1="2" x2="16" y2="6" />
                                    <line x1="8" y1="2" x2="8" y2="6" />
                                    <line x1="3" y1="10" x2="21" y2="10" />
                                    <line x1="12" y1="15" x2="12" y2="19" />
                                    <line x1="10" y1="17" x2="14" y2="17" />
                                </svg> Reserve
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Room 3 -->
                <div class="room-card">
                    <div class="room-img"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="48" height="48" style="width:48px;height:48px;" aria-label="Hall" aria-hidden="true">
                            <rect x="2" y="3" width="20" height="14" rx="2" />
                            <line x1="8" y1="21" x2="16" y2="21" />
                            <line x1="12" y1="17" x2="12" y2="21" />
                            <path d="M9 10l2 2 4-4" />
                        </svg></div>
                    <div class="room-info">
                        <div>
                            <div class="room-header">
                                <div>
                                    <h3>Lecture Hall A</h3>
                                    <p>Ground Floor, Academic Building</p>
                                </div>
                                <span class="capacity-badge"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14" style="width:14px;height:14px;margin-right:6px;vertical-align:middle;" aria-label="Seats" aria-hidden="true">
                                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" />
                                        <circle cx="9" cy="7" r="4" />
                                        <path d="M23 21v-2a4 4 0 0 0-3-3.87" />
                                        <path d="M16 3.13a4 4 0 0 1 0 7.75" />
                                    </svg>80 seats</span>
                            </div>
                            <div class="amenities" style="margin-top:10px;">
                                <span><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14" style="width:14px;height:14px;margin-right:6px;vertical-align:middle;" aria-label="WiFi" aria-hidden="true">
                                        <path d="M1.42 9a16 16 0 0 1 21.16 0" />
                                        <path d="M5 12.55a11 11 0 0 1 14.08 0" />
                                        <path d="M8.53 16.11a6 6 0 0 1 6.95 0" />
                                        <line x1="12" y1="20" x2="12.01" y2="20" />
                                    </svg> WiFi</span>
                                <span><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14" style="width:14px;height:14px;margin-right:6px;vertical-align:middle;" aria-label="A/C" aria-hidden="true">
                                        <path d="M17.7 7.7a2.5 2.5 0 1 1 1.8 4.3H2" />
                                        <path d="M9.6 4.6A2 2 0 1 1 11 8H2" />
                                        <path d="M12.6 19.4A2 2 0 1 0 14 16H2" />
                                    </svg> A/C</span>
                                <span><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14" style="width:14px;height:14px;margin-right:6px;vertical-align:middle;" aria-label="PA" aria-hidden="true">
                                        <path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z" />
                                        <path d="M19 10v2a7 7 0 0 1-14 0v-2" />
                                        <line x1="12" y1="19" x2="12" y2="23" />
                                        <line x1="8" y1="23" x2="16" y2="23" />
                                    </svg> PA System</span>
                                <span><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14" style="width:14px;height:14px;margin-right:6px;vertical-align:middle;" aria-label="Projector" aria-hidden="true">
                                        <rect x="2" y="7" width="20" height="15" rx="2" ry="2" />
                                        <polyline points="17 2 12 7 7 2" />
                                    </svg> Projector</span>
                            </div>
                        </div>
                        <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;">
                            <span class="room-avail"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 8 8" width="6" height="6" aria-hidden="true" style="vertical-align:middle;margin-right:6px;opacity:0.85;">
                                    <circle cx="4" cy="4" r="4" fill="currentColor" />
                                </svg> Available</span>
                            <button class="btn-borrow" style="width:auto; padding:9px 24px;"
                                data-action="open-room-form" data-room="Lecture Hall A">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14" style="width:14px;height:14px;margin-right:6px;vertical-align:middle;" aria-label="Reserve" aria-hidden="true">
                                    <rect x="3" y="4" width="18" height="18" rx="2" ry="2" />
                                    <line x1="16" y1="2" x2="16" y2="6" />
                                    <line x1="8" y1="2" x2="8" y2="6" />
                                    <line x1="3" y1="10" x2="21" y2="10" />
                                    <line x1="12" y1="15" x2="12" y2="19" />
                                    <line x1="10" y1="17" x2="14" y2="17" />
                                </svg> Reserve
                            </button>
                        </div>
                    </div>
                </div>
            </div><!-- /roomList -->

            <!-- Room Reservation Form (hidden until Reserve clicked) -->
            <div id="room-form-section" class="hidden" style="margin-top:2rem;">
                <div class="eq-card room-form-card">
                    <div class="form-card-header">
                        <h2><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon-img" style="color:var(--accent-maroon); margin-right:8px" aria-label="Room Form" aria-hidden="true">
                                <rect x="3" y="3" width="18" height="18" rx="2" ry="2" />
                                <line x1="9" y1="3" x2="9" y2="21" />
                                <circle cx="6" cy="12" r="1" fill="currentColor" stroke="none" />
                            </svg>Room Reservation Form</h2>
                        <button class="btn-close-custom" data-action="close-room-form" title="Close">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16" style="width:16px;height:16px;" aria-label="Close" aria-hidden="true">
                                <line x1="18" y1="6" x2="6" y2="18" />
                                <line x1="6" y1="6" x2="18" y2="18" />
                            </svg>
                        </button>
                    </div>
                    <div class="form-card-body">
                        <div class="selected-item-banner" id="selectedRoomBanner">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="18" height="18" style="width:18px;height:18px;" aria-label="Room" aria-hidden="true">
                                <rect x="3" y="3" width="18" height="18" rx="2" ry="2" />
                                <line x1="9" y1="3" x2="9" y2="21" />
                                <circle cx="6" cy="12" r="1" fill="currentColor" stroke="none" />
                            </svg>
                            <span id="selectedRoomLabel">No room selected</span>
                        </div>

                        <div class="coming-soon-banner" style="margin-bottom:1.5rem;">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon-img" aria-label="Info" aria-hidden="true">
                                <circle cx="12" cy="12" r="10" />
                                <line x1="12" y1="8" x2="12" y2="12" />
                                <line x1="12" y1="16" x2="12.01" y2="16" />
                            </svg>
                            <h3>Preview Mode</h3>
                            <p>Reservations are not yet processed. This form shows the planned layout.</p>
                        </div>

                        <div class="form-group">
                            <label>Purpose / Event Name</label>
                            <input type="text" class="form-control-custom" placeholder="e.g. BSIT Capstone Defense">
                        </div>
                        <div class="form-group">
                            <label>Instructor / Adviser</label>
                            <input type="text" class="form-control-custom" placeholder="e.g. Sir. Migs">
                        </div>
                        <div class="form-group">
                            <label>Number of Attendees</label>
                            <input type="number" class="form-control-custom" placeholder="e.g. 25" min="1">
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Reservation Date</label>
                                <input type="date" class="form-control-custom">
                            </div>
                            <div class="form-group">
                                <label>Time Slot</label>
                                <select class="form-control-custom">
                                    <option value="">Select time slot</option>
                                    <option>7:00 AM – 9:00 AM</option>
                                    <option>9:00 AM – 11:00 AM</option>
                                    <option>11:00 AM – 1:00 PM</option>
                                    <option>1:00 PM – 3:00 PM</option>
                                    <option>3:00 PM – 5:00 PM</option>
                                    <option>Full Day (7 AM – 5 PM)</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Additional Notes</label>
                            <textarea class="form-control-custom" rows="3" placeholder="Any special requirements or notes..."></textarea>
                        </div>
                        <button type="button" class="btn-submit-form" data-action="room-reserve-preview">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16" style="width:16px;height:16px;margin-right:8px;" aria-label="Submit" aria-hidden="true">
                                <line x1="22" y1="2" x2="11" y2="13" />
                                <polygon points="22 2 15 22 11 13 2 9 22 2" />
                            </svg> Submit Reservation (Preview)
                        </button>
                    </div>
                </div>
            </div>
        </div><!-- /panel-rooms -->

    </main>

    <!-- ================================================================
     OVERLAY: ACCOUNT PAGE
================================================================ -->
    <div class="overlay-page" id="accountOverlay">

        <!-- Own top bar — replaces the hidden app header while overlay is open -->
        <div class="overlay-topbar">
            <button class="overlay-topbar-back" data-action="close-overlay" data-target="accountOverlay">
                <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;margin-right:4px;">
                    <line x1="19" y1="12" x2="5" y2="12" />
                    <polyline points="12 5 5 12 12 19" />
                </svg> Back to Dashboard
            </button>
            <div class="overlay-topbar-sep"></div>
            <span class="overlay-topbar-title">My Account</span>
            <div class="overlay-topbar-brand">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-label="PUPSYNC" aria-hidden="true">
                    <polygon points="12 2 2 7 12 12 22 7 12 2" />
                    <polyline points="2 17 12 22 22 17" />
                    <polyline points="2 12 12 17 22 12" />
                </svg>
                <span>PUPSYNC</span>
            </div>
        </div>

        <div class="account-layout">
            <div class="account-sidebar">
                <span class="account-sidebar-label">My Account</span>
                <button class="acc-nav-btn active" data-acc-tab="acc-overview">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon-img" aria-label="Overview" aria-hidden="true">
                        <rect x="2" y="5" width="20" height="14" rx="2" />
                        <circle cx="8" cy="12" r="2" />
                        <path d="M14 9h4" />
                        <path d="M14 12h4" />
                        <path d="M14 15h2" />
                    </svg> Overview
                </button>
                <button class="acc-nav-btn" data-acc-tab="acc-academic">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon-img" aria-label="Academic" aria-hidden="true">
                        <path d="M22 10v6" />
                        <path d="M2 10l10-5 10 5-10 5z" />
                        <path d="M6 12v5c3 3 9 3 12 0v-5" />
                    </svg> Academic Info
                </button>
                <button class="acc-nav-btn" data-acc-tab="acc-contact">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon-img" aria-label="Contact" aria-hidden="true">
                        <path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z" />
                        <path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z" />
                    </svg> Contact Details
                </button>
                <span class="account-sidebar-label" style="margin-top:0.5rem;">Emergency</span>
                <button class="acc-nav-btn" data-acc-tab="acc-emergency">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon-img" aria-label="Emergency" aria-hidden="true">
                        <polyline points="22 12 18 12 15 21 9 3 6 12 2 12" />
                    </svg> Emergency Contact
                </button>
            </div>

            <div class="account-content">
                <!-- Overview -->
                <div id="acc-overview" class="overlay-sub-panel active">
                    <div class="overlay-section-header" style="margin-bottom:1.4rem;">
                        <span class="section-eyebrow">My Account › Overview</span>
                        <h2>Profile &amp; Identity</h2>
                        <p>Your personal details and login information.</p>
                    </div>
                    <div class="account-hero-card">
                        <div class="acc-avatar-large">
                            <?php echo htmlspecialchars($initials); ?>
                            <div class="cam-btn" title="Change photo"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14" style="width:14px;height:14px;" aria-label="Camera" aria-hidden="true">
                                    <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z" />
                                    <circle cx="12" cy="13" r="4" />
                                </svg></div>
                        </div>
                        <div class="acc-hero-info">
                            <h2><?php echo htmlspecialchars($fullname); ?></h2>
                            <p>ID: <?php echo htmlspecialchars($_SESSION['user_id']); ?></p>
                            <span class="acc-badge">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="12" height="12" aria-hidden="true" style="vertical-align:middle;margin-right:6px;">
                                    <circle cx="12" cy="12" r="7" fill="#22c55e" stroke="none" />
                                </svg>
                                Active Student
                            </span>
                        </div>
                        <div class="acc-action-wrap">
                            <button class="btn-edit-acc" id="editProfileBtn" data-action="profile-edit">
                                Edit Profile
                            </button>
                            <button class="btn-save-acc" id="saveProfileBtn" style="display:none;" data-action="profile-save">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14" style="width:14px;height:14px;margin-right:6px;vertical-align:middle;" aria-label="Save" aria-hidden="true">
                                    <polyline points="20 6 9 17 4 12" />
                                </svg> Save
                            </button>
                            <button class="btn-cancel-acc" id="cancelProfileBtn" style="display:none;" data-action="profile-cancel">
                                Cancel
                            </button>
                        </div>
                    </div>

                    <div class="info-card">
                        <div class="info-card-head">
                            <h3>Personal Information</h3>
                        </div>
                        <div class="info-row">
                            <span class="info-lbl">Full Name</span>
                            <span class="info-val" data-field="fullname"><?php echo htmlspecialchars($fullname); ?></span>
                            <input class="info-input-f" data-input="fullname" value="<?php echo htmlspecialchars($fullname); ?>" disabled style="display:none;">
                        </div>
                        <div class="info-row">
                            <span class="info-lbl">Student ID</span>
                            <span class="info-val"><?php echo htmlspecialchars($_SESSION['user_id']); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-lbl">Date of Birth</span>
                            <span class="info-val empty" data-field="dob">— Not provided</span>
                            <input class="info-input-f" type="date" data-input="dob" disabled style="display:none;">
                        </div>
                        <div class="info-row">
                            <span class="info-lbl">Gender</span>
                            <span class="info-val empty" data-field="gender">— Not provided</span>
                            <select class="info-input-f" data-input="gender" disabled style="display:none;">
                                <option value="">Select...</option>
                                <option>Male</option>
                                <option>Female</option>
                                <option>Prefer not to say</option>
                            </select>
                        </div>
                        <div class="info-row">
                            <span class="info-lbl">Nationality</span>
                            <span class="info-val empty" data-field="nationality">— Not provided</span>
                            <input class="info-input-f" data-input="nationality" placeholder="e.g. Filipino" disabled style="display:none;">
                        </div>
                    </div>

                    <div class="info-card">
                        <div class="info-card-head">
                            <h3>Login & Security</h3>
                        </div>
                        <div class="info-row">
                            <span class="info-lbl">Email</span>
                            <span class="info-val empty" data-field="email">— Not provided</span>
                            <input class="info-input-f" data-input="email" type="email" placeholder="your@school.edu.ph" disabled style="display:none;">
                        </div>
                        <div class="info-row">
                            <span class="info-lbl">Password</span>
                            <span class="info-val">••••••••••</span>
                            <button class="btn-borrow" style="width:auto; padding:6px 16px; font-size:0.75rem; margin-left:auto;"
                                data-action="toast" data-msg="Change password feature coming soon!">
                                Change
                            </button>
                        </div>
                    </div>
                </div><!-- /acc-overview -->

                <!-- Academic -->
                <div id="acc-academic" class="overlay-sub-panel">
                    <div class="overlay-section-header">
                        <span class="section-eyebrow">My Account › Academic</span>
                        <h2>Academic Information</h2>
                        <p>Your enrollment and program details.</p>
                    </div>
                    <div class="info-card">
                        <div class="info-card-head">
                            <h3>Enrollment</h3>
                        </div>
                        <div class="info-row"><span class="info-lbl">Student ID</span><span class="info-val"><?php echo htmlspecialchars($_SESSION['user_id']); ?></span></div>
                        <div class="info-row"><span class="info-lbl">Full Name</span><span class="info-val"><?php echo htmlspecialchars($fullname); ?></span></div>
                        <div class="info-row"><span class="info-lbl">Program</span><span class="info-val empty">— Not provided</span></div>
                        <div class="info-row"><span class="info-lbl">Year Level</span><span class="info-val empty">— Not provided</span></div>
                        <div class="info-row"><span class="info-lbl">Section</span><span class="info-val empty">— Not provided</span></div>
                        <div class="info-row"><span class="info-lbl">Status</span><span class="info-val"><span class="stock-badge stock-avail">Active / Regular</span></span></div>
                    </div>
                </div>

                <!-- Contact -->
                <div id="acc-contact" class="overlay-sub-panel">
                    <div class="overlay-section-header">
                        <span class="section-eyebrow">My Account › Contact</span>
                        <h2>Contact Details</h2>
                        <p>How we can reach you.</p>
                    </div>
                    <div class="info-card">
                        <div class="info-card-head">
                            <h3>Address</h3>
                        </div>
                        <div class="info-row"><span class="info-lbl">Present Address</span><span class="info-val empty">— Not provided</span></div>
                        <div class="info-row"><span class="info-lbl">Permanent Address</span><span class="info-val empty">— Not provided</span></div>
                    </div>
                    <div class="info-card">
                        <div class="info-card-head">
                            <h3>Phone</h3>
                        </div>
                        <div class="info-row"><span class="info-lbl">Mobile Number</span><span class="info-val empty">— Not provided</span></div>
                        <div class="info-row"><span class="info-lbl">Landline</span><span class="info-val empty">— Not provided</span></div>
                    </div>
                </div>

                <!-- Emergency -->
                <div id="acc-emergency" class="overlay-sub-panel">
                    <div class="overlay-section-header">
                        <span class="section-eyebrow">My Account › Emergency</span>
                        <h2>Emergency Contact</h2>
                        <p>Person to contact in an emergency.</p>
                    </div>
                    <div class="info-card">
                        <div class="info-card-head">
                            <h3>Primary Contact</h3>
                        </div>
                        <div class="info-row"><span class="info-lbl">Name</span><span class="info-val empty">— Not provided</span></div>
                        <div class="info-row"><span class="info-lbl">Relationship</span><span class="info-val empty">— Not provided</span></div>
                        <div class="info-row"><span class="info-lbl">Mobile Number</span><span class="info-val empty">— Not provided</span></div>
                    </div>
                </div>
            </div>
        </div>
    </div><!-- /accountOverlay -->

    <!-- ================================================================
     OVERLAY: SETTINGS PAGE
================================================================ -->
    <div class="overlay-page" id="settingsOverlay">

        <!-- Own top bar -->
        <div class="overlay-topbar">
            <button class="overlay-topbar-back" data-action="close-overlay" data-target="settingsOverlay">
                <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;margin-right:4px;">
                    <line x1="19" y1="12" x2="5" y2="12" />
                    <polyline points="12 5 5 12 12 19" />
                </svg> Back to Dashboard
            </button>
            <div class="overlay-topbar-sep"></div>
            <span class="overlay-topbar-title">Settings</span>
            <div class="overlay-topbar-brand">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-label="PUPSYNC" aria-hidden="true">
                    <polygon points="12 2 2 7 12 12 22 7 12 2" />
                    <polyline points="2 17 12 22 22 17" />
                    <polyline points="2 12 12 17 22 12" />
                </svg>
                <span>PUPSYNC</span>
            </div>
        </div>

        <div class="settings-layout">
            <div class="settings-sidebar">
                <span class="s-cat-label">Appearance</span>
                <button class="s-nav-item active" data-sett-tab="st-appearance"><svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;margin-right:6px;">
                        <circle cx="13.5" cy="6.5" r="0.5" fill="currentColor" />
                        <circle cx="17.5" cy="10.5" r="0.5" fill="currentColor" />
                        <circle cx="8.5" cy="7.5" r="0.5" fill="currentColor" />
                        <circle cx="6.5" cy="12.5" r="0.5" fill="currentColor" />
                        <path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.554C21.965 6.012 17.461 2 12 2z" />
                    </svg> Appearance</button>
                <button class="s-nav-item" data-sett-tab="st-accessibility"><svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;margin-right:6px;">
                        <circle cx="12" cy="6" r="2" />
                        <path d="m4 14 8-2 8 2" />
                        <path d="M8 12v1.5l-3 5" />
                        <path d="M16 12v1.5l3 5" />
                        <path d="m9 22 3-6 3 6" />
                    </svg> Accessibility</button>
                <span class="s-cat-label">Account</span>
                <button class="s-nav-item" data-sett-tab="st-privacy"><svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;margin-right:6px;">
                        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" />
                        <polyline points="9 12 11 14 15 10" />
                    </svg> Privacy &amp; Security</button>
                <button class="s-nav-item" data-sett-tab="st-notif-prefs"><svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;margin-right:6px;">
                        <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9" />
                        <path d="M13.73 21a2 2 0 0 1-3.46 0" />
                    </svg> Notifications</button>
                <!-- <button class="s-nav-item" data-sett-tab="st-language"><i class="fa-solid fa-language"></i> Language & Region</button> -->
                <div class="s-divider"></div>
                <span class="s-cat-label">System</span>
                <button class="s-nav-item" data-sett-tab="st-advanced"><svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;margin-right:6px;">
                        <line x1="4" y1="21" x2="4" y2="14" />
                        <line x1="4" y1="10" x2="4" y2="3" />
                        <line x1="12" y1="21" x2="12" y2="12" />
                        <line x1="12" y1="8" x2="12" y2="3" />
                        <line x1="20" y1="21" x2="20" y2="16" />
                        <line x1="20" y1="12" x2="20" y2="3" />
                        <line x1="1" y1="14" x2="7" y2="14" />
                        <line x1="9" y1="8" x2="15" y2="8" />
                        <line x1="17" y1="16" x2="23" y2="16" />
                    </svg> Advanced</button>
            </div>

            <div class="settings-content">

                <!-- Appearance -->
                <div id="st-appearance" class="overlay-sub-panel active">
                    <div class="overlay-section-header">
                        <span class="section-eyebrow">Settings › Appearance</span>
                        <h2>Appearance</h2>
                        <p>Customize how the portal looks and feels.</p>
                    </div>

                    <div class="settings-card">
                        <div class="settings-card-head">
                            <h3>Theme</h3>
                            <p>Choose between light, dark, or high-contrast mode.</p>
                        </div>
                        <div class="theme-grid">
                            <div class="theme-opt selected" id="tp-light" data-action="apply-theme" data-theme="light">
                                <div class="theme-prev tp-light">
                                    <div class="theme-prev-bar"></div>
                                    <div class="theme-prev-bar"></div>
                                    <div class="theme-prev-bar"></div>
                                </div>
                                <div class="theme-lbl">Light <svg id="tc-light" xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round" style="color:var(--accent-maroon);vertical-align:middle;">
                                        <polyline points="20 6 9 17 4 12" />
                                    </svg></div>
                            </div>
                            <div class="theme-opt" id="tp-dark" data-action="apply-theme" data-theme="dark">
                                <div class="theme-prev tp-dark">
                                    <div class="theme-prev-bar"></div>
                                    <div class="theme-prev-bar"></div>
                                    <div class="theme-prev-bar"></div>
                                </div>
                                <div class="theme-lbl">Dark <svg id="tc-dark" xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round" style="color:var(--accent-maroon);vertical-align:middle;display:none;">
                                        <polyline points="20 6 9 17 4 12" />
                                    </svg></div>
                            </div>
                            <div class="theme-opt" id="tp-hc" data-action="apply-theme" data-theme="high-contrast">
                                <div class="theme-prev tp-hc">
                                    <div class="theme-prev-bar"></div>
                                    <div class="theme-prev-bar"></div>
                                    <div class="theme-prev-bar"></div>
                                </div>
                                <div class="theme-lbl">High Contrast <svg id="tc-hc" xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round" style="color:var(--accent-maroon);vertical-align:middle;display:none;">
                                        <polyline points="20 6 9 17 4 12" />
                                    </svg></div>
                            </div>
                        </div>
                    </div>

                    <div class="settings-card">
                        <div class="settings-card-head">
                            <h3>Accent Color</h3>
                            <p>Pick a highlight color for buttons and active elements.</p>
                        </div>
                        <div class="color-dots">
                            <div class="c-dot selected" style="background:#600302;" data-action="apply-accent" data-color="#600302" data-light="#f3e5e6" title="Maroon (Default)"></div>
                            <div class="c-dot" style="background:#1a5276;" data-action="apply-accent" data-color="#1a5276" data-light="#d6eaf8" title="Navy Blue"></div>
                            <div class="c-dot" style="background:#1e8449;" data-action="apply-accent" data-color="#1e8449" data-light="#d5f5e3" title="Forest Green"></div>
                            <div class="c-dot" style="background:#7d3c98;" data-action="apply-accent" data-color="#7d3c98" data-light="#f0e6fa" title="Purple"></div>
                            <div class="c-dot" style="background:#d35400;" data-action="apply-accent" data-color="#d35400" data-light="#fde8d8" title="Burnt Orange"></div>
                            <div class="c-dot" style="background:#2e86c1;" data-action="apply-accent" data-color="#2e86c1" data-light="#d6eaf8" title="Sky Blue"></div>
                        </div>
                    </div>

                    <div class="settings-card">
                        <div class="settings-card-head">
                            <h3>Compact Mode</h3>
                            <p>Reduce spacing for a denser layout.</p>
                        </div>
                        <div class="s-row">
                            <div class="s-row-label">
                                <h4>Enable Compact Mode</h4>
                                <p>Makes cards and list items smaller.</p>
                            </div>
                            <label class="toggle-sw">
                                <input type="checkbox" id="compactToggle" data-action="apply-compact">
                                <span class="toggle-track"></span>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Accessibility -->
                <div id="st-accessibility" class="overlay-sub-panel">
                    <div class="overlay-section-header">
                        <span class="section-eyebrow">Settings › Accessibility</span>
                        <h2>Accessibility</h2>
                        <p>Make the portal easier to use.</p>
                    </div>

                    <div class="settings-card">
                        <div class="settings-card-head">
                            <h3>Text Size</h3>
                        </div>
                        <div class="range-wrap">
                            <h4>Font Size <span id="fontSizeLbl" style="color:var(--accent-maroon);">100%</span></h4>
                            <div class="range-labels"><span>Small</span><span>Default</span><span>Large</span></div>
                            <input type="range" min="80" max="130" value="100" step="5" id="fontSizeRange" data-action="apply-fontsize">
                        </div>
                    </div>

                    <div class="settings-card">
                        <div class="settings-card-head">
                            <h3>Motion & Animations</h3>
                        </div>
                        <div class="s-row">
                            <div class="s-row-label">
                                <h4>Reduce Motion</h4>
                                <p>Disables fade-in and slide animations.</p>
                            </div>
                            <label class="toggle-sw">
                                <input type="checkbox" id="reduceMotionToggle" data-action="apply-reduce-motion">
                                <span class="toggle-track"></span>
                            </label>
                        </div>
                    </div>

                    <div class="settings-card">
                        <div class="settings-card-head">
                            <h3>Focus Indicators</h3>
                        </div>
                        <div class="s-row">
                            <div class="s-row-label">
                                <h4>Enhanced Focus Ring</h4>
                                <p>Makes keyboard focus outlines more visible.</p>
                            </div>
                            <label class="toggle-sw">
                                <input type="checkbox" id="focusRingToggle" data-action="apply-focus-ring">
                                <span class="toggle-track"></span>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Privacy -->
                <div id="st-privacy" class="overlay-sub-panel">
                    <div class="overlay-section-header">
                        <span class="section-eyebrow">Settings › Privacy</span>
                        <h2>Privacy &amp; Security</h2>
                        <p>Control your data and account security.</p>
                    </div>
                    <div class="settings-card">
                        <div class="settings-card-head">
                            <h3>Login Sessions</h3>
                        </div>
                        <div class="s-row">
                            <div class="s-row-label">
                                <h4>Remember Me</h4>
                                <p>Stay logged in for 30 days.</p>
                            </div>
                            <label class="toggle-sw"><input type="checkbox" checked><span class="toggle-track"></span></label>
                        </div>
                        <div class="s-row">
                            <div class="s-row-label">
                                <h4>Two-Factor Authentication</h4>
                                <p>Add an extra layer of security.</p>
                            </div>
                            <button class="btn-borrow" style="width:auto;padding:7px 16px;font-size:0.78rem;"
                                data-action="toast" data-msg="2FA setup coming soon!">Enable 2FA</button>
                        </div>
                    </div>
                    <div class="settings-card danger-card">
                        <div class="settings-card-head">
                            <h3 style="color:var(--danger);">Danger Zone</h3>
                            <p>Irreversible actions.</p>
                        </div>
                        <div class="s-row">
                            <div class="s-row-label">
                                <h4>Clear All Activity Data</h4>
                                <p>Permanently wipes your history.</p>
                            </div>
                            <button class="btn-danger-sm" data-action="toast" data-msg="This action is admin-restricted.">Clear Data</button>
                        </div>
                    </div>
                </div>

                <!-- Notification Prefs -->
                <div id="st-notif-prefs" class="overlay-sub-panel">
                    <div class="overlay-section-header">
                        <span class="section-eyebrow">Settings › Notifications</span>
                        <h2>Notification Preferences</h2>
                        <p>Control which notifications you receive.</p>
                    </div>
                    <div class="settings-card">
                        <div class="settings-card-head">
                            <h3>Borrow & Return Alerts</h3>
                        </div>
                        <div class="s-row">
                            <div class="s-row-label">
                                <h4>Borrow Approved</h4>
                                <p>Notify when my request is approved.</p>
                            </div><label class="toggle-sw"><input type="checkbox" checked><span class="toggle-track"></span></label>
                        </div>
                        <div class="s-row">
                            <div class="s-row-label">
                                <h4>Due Date Reminder</h4>
                                <p>Remind me 1 day before equipment is due.</p>
                            </div><label class="toggle-sw"><input type="checkbox" checked><span class="toggle-track"></span></label>
                        </div>
                        <div class="s-row">
                            <div class="s-row-label">
                                <h4>Overdue Warning</h4>
                                <p>Alert when I have an overdue item.</p>
                            </div><label class="toggle-sw"><input type="checkbox" checked><span class="toggle-track"></span></label>
                        </div>
                    </div>
                    <div class="settings-card">
                        <div class="settings-card-head">
                            <h3>General</h3>
                        </div>
                        <div class="s-row">
                            <div class="s-row-label">
                                <h4>School Announcements</h4>
                                <p>Upcoming events.</p>
                            </div><label class="toggle-sw"><input type="checkbox" checked><span class="toggle-track"></span></label>
                        </div>
                        <div class="s-row">
                            <div class="s-row-label">
                                <h4>System Updates</h4>
                                <p>Maintenance alerts.</p>
                            </div><label class="toggle-sw"><input type="checkbox" checked><span class="toggle-track"></span></label>
                        </div>
                    </div>
                </div>


                <!-- Language & Region (commented out for now since we only have English, but structure is ready for future localization features)
                
                <div id="st-language" class="overlay-sub-panel">
                    <h2 class="settings-title">Language & Region</h2>
                    <p class="settings-desc">Set your preferred language and date/time format.</p>
                    <div class="settings-card">
                        <div class="settings-card-head">
                            <h3>Language</h3>
                        </div>
                        <div class="s-row">
                            <div class="s-row-label">
                                <h4>Display Language</h4>
                            </div>
                            <select class="s-select">
                                <option selected>English (Philippines)</option>
                                <option>Filipino</option>
                                <option>English (US)</option>
                            </select>
                        </div>
                    </div>
                    <div class="settings-card">
                        <div class="settings-card-head">
                            <h3>Date & Time</h3>
                        </div>
                        <div class="s-row">
                            <div class="s-row-label">
                                <h4>Time Zone</h4>
                            </div><select class="s-select">
                                <option selected>Asia/Manila (UTC+8)</option>
                                <option>UTC</option>
                            </select>
                        </div>
                        <div class="s-row">
                            <div class="s-row-label">
                                <h4>Date Format</h4>
                            </div><select class="s-select">
                                <option>MM/DD/YYYY</option>
                                <option selected>DD/MM/YYYY</option>
                                <option>YYYY-MM-DD</option>
                            </select>
                        </div>
                        <div class="s-row">
                            <div class="s-row-label">
                                <h4>Time Format</h4>
                            </div><select class="s-select">
                                <option selected>12-hour (AM/PM)</option>
                                <option>24-hour</option>
                            </select>
                        </div>
                    </div>
                </div>
                -->

                <!-- Advanced -->
                <div id="st-advanced" class="overlay-sub-panel">
                    <div class="overlay-section-header">
                        <span class="section-eyebrow">Settings › Advanced</span>
                        <h2>Advanced</h2>
                        <p>Power user settings. Be careful.</p>
                    </div>
                    <div class="settings-card">
                        <div class="settings-card-head">
                            <h3>Display</h3>
                        </div>
                        <div class="s-row">
                            <div class="s-row-label">
                                <h4>Show Asset IDs</h4>
                                <p>Display equipment asset IDs.</p>
                            </div><label class="toggle-sw"><input type="checkbox" checked><span class="toggle-track"></span></label>
                        </div>
                        <div class="s-row">
                            <div class="s-row-label">
                                <h4>Verbose Error Messages</h4>
                                <p>Show detailed error info.</p>
                            </div><label class="toggle-sw"><input type="checkbox"><span class="toggle-track"></span></label>
                        </div>
                    </div>
                    <div class="settings-card">
                        <div class="settings-card-head">
                            <h3>Reset</h3>
                        </div>
                        <div class="s-row">
                            <div class="s-row-label">
                                <h4>Reset All Settings</h4>
                                <p>Restore defaults.</p>
                            </div>
                            <button class="btn-danger-sm" data-action="reset-settings">Reset</button>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div><!-- /settingsOverlay -->

    <!-- ================================================================
     OVERLAY: NOTIFICATIONS
================================================================ -->
    <div class="overlay-page" id="notifOverlay" style="display:flex; flex-direction:column; overflow-y:auto;">

        <!-- Own top bar -->
        <div class="overlay-topbar" style="flex-shrink:0;">
            <button class="overlay-topbar-back" data-action="close-overlay" data-target="notifOverlay">
                <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;margin-right:4px;">
                    <line x1="19" y1="12" x2="5" y2="12" />
                    <polyline points="12 5 5 12 12 19" />
                </svg> Back to Dashboard
            </button>
            <div class="overlay-topbar-sep"></div>
            <span class="overlay-topbar-title">Notifications</span>
            <div class="overlay-topbar-brand">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-label="PUPSYNC" aria-hidden="true">
                    <polygon points="12 2 2 7 12 12 22 7 12 2" />
                    <polyline points="2 17 12 22 22 17" />
                    <polyline points="2 12 12 17 22 12" />
                </svg>
                <span>PUPSYNC</span>
            </div>
        </div>

        <div class="notif-wrapper">
            <div style="display:flex; align-items:flex-start; justify-content:space-between; margin-bottom:1.2rem; flex-wrap:wrap; gap:10px;">
                <div class="overlay-section-header" style="flex:1; margin-bottom:0;">
                    <span class="section-eyebrow">Inbox › All Notifications</span>
                    <h2>Notifications</h2>
                    <p>You have <strong style="color:var(--accent-maroon);" id="unreadCount"><?php echo (3 + count($overdue_notifs)); ?> unread</strong> notifications.</p>
                </div>
                <button class="mark-read-btn" data-action="mark-all-read" style="margin-top:0.5rem;">Mark all as read</button>
            </div>

            <div class="notif-filter-tabs">
                <button class="notif-tab active" data-notif-filter="all">All</button>
                <button class="notif-tab" data-notif-filter="unread">Unread</button>
                <button class="notif-tab" data-notif-filter="borrow">Borrow</button>
                <button class="notif-tab" data-notif-filter="overdue">Overdue</button>
                <button class="notif-tab" data-notif-filter="system">System</button>
            </div>

            <?php if (!empty($overdue_notifs)): ?>
            <div class="notif-group overdue-notif-group">⚠️ Overdue — Action Required</div>
            <?php foreach ($overdue_notifs as $on): ?>
            <div class="notif-item unread notif-overdue" data-cat="overdue">
                <div class="notif-icon ni-overdue"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                    <line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
                </svg></div>
                <div class="notif-body-wrap">
                    <h4>Overdue Item: <?php echo htmlspecialchars($on['equipment_name']); ?></h4>
                    <p>This item was due on <strong><?php echo htmlspecialchars($on['return_date']); ?></strong>. Please return it to the admin immediately to avoid penalties.</p>
                </div>
                <div class="notif-meta"><span class="notif-time">Overdue since <?php echo htmlspecialchars($on['return_date']); ?></span><div class="unread-dot"></div></div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>

            <div class="notif-group">Today</div>
            <div class="notif-item unread" data-cat="borrow">
                <div class="notif-icon ni-success"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="20 6 9 17 4 12" />
                    </svg></div>
                <div class="notif-body-wrap">
                    <h4>Borrow Request Approved</h4>
                    <p>Your latest borrow request has been approved. Please pick up the item at the Admin Office before 5:00 PM.</p>
                </div>
                <div class="notif-meta"><span class="notif-time">9:42 AM</span>
                    <div class="unread-dot"></div>
                </div>
            </div>
            <div class="notif-item unread" data-cat="system">
                <div class="notif-icon ni-alert"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="3" />
                        <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z" />
                    </svg></div>
                <div class="notif-body-wrap">
                    <h4>System Maintenance Tonight</h4>
                    <p>PUPSYNC will undergo scheduled maintenance from 11:00 PM to 1:00 AM.</p>
                </div>
                <div class="notif-meta"><span class="notif-time">8:00 AM</span>
                    <div class="unread-dot"></div>
                </div>
            </div>

            <div class="notif-group">Yesterday</div>
            <div class="notif-item unread" data-cat="borrow">
                <div class="notif-icon ni-warn"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" />
                        <line x1="12" y1="9" x2="12" y2="13" />
                        <line x1="12" y1="17" x2="12.01" y2="17" />
                    </svg></div>
                <div class="notif-body-wrap">
                    <h4>Return Reminder</h4>
                    <p>You have a borrowed item due in 1 day. Please return it on time to avoid penalties.</p>
                </div>
                <div class="notif-meta"><span class="notif-time">Yesterday, 4:15 PM</span>
                    <div class="unread-dot"></div>
                </div>
            </div>
            <div class="notif-item" data-cat="borrow">
                <div class="notif-icon ni-success"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z" />
                        <polyline points="3.27 6.96 12 12.01 20.73 6.96" />
                        <line x1="12" y1="22.08" x2="12" y2="12" />
                    </svg></div>
                <div class="notif-body-wrap">
                    <h4>Request Submitted</h4>
                    <p>Your borrow request for Lab Equipment was successfully submitted and is under review.</p>
                </div>
                <div class="notif-meta"><span class="notif-time">Yesterday, 2:00 PM</span></div>
            </div>
        </div>
    </div><!-- /notifOverlay -->

    <!-- Loading Overlay -->
    <div id="loading-overlay">
        <div class="spinner"></div>
        <p style="margin-top:1rem; font-weight:600; color:var(--text-dark); font-size:0.9rem;">Processing your request...</p>
    </div>

    <!-- Toast -->
    <div id="app-toast"></div>


    <!-- ================================================================
     JAVASCRIPT — Single event-delegation model. No inline onclick.
================================================================ -->
    <script>
        (function() {
            'use strict';

            const todayStr = new Date().toISOString().split('T')[0];

            /* ══════════════════════════════════════════════════════════════════
               STATE PERSISTENCE — localStorage
               Saves settings, account edits, and notification read state so
               everything survives a page reload. Active tab is intentionally
               NOT restored (reload always lands on Home per UX contract).
            ══════════════════════════════════════════════════════════════════ */
            const LS = {
                get: k => {
                    try {
                        return localStorage.getItem('eq_' + k);
                    } catch (e) {
                        return null;
                    }
                },
                set: (k, v) => {
                    try {
                        localStorage.setItem('eq_' + k, String(v));
                    } catch (e) {}
                },
                del: k => {
                    try {
                        localStorage.removeItem('eq_' + k);
                    } catch (e) {}
                },
                getJ: k => {
                    try {
                        return JSON.parse(localStorage.getItem('eq_' + k) || 'null');
                    } catch (e) {
                        return null;
                    }
                },
                setJ: (k, v) => {
                    try {
                        localStorage.setItem('eq_' + k, JSON.stringify(v));
                    } catch (e) {}
                }
            };

            /* ── Restore all persisted state on load ─────────────────────── */
            function restorePersistedState() {
                // 1. Theme
                const theme = LS.get('theme');
                if (theme && theme !== 'light') _applyThemeDOM(theme);

                // 2. Accent color
                const ac = LS.get('accentColor'),
                    al = LS.get('accentLight');
                if (ac) _applyAccentDOM(ac, al || '#f3e5e6');

                // 3. Compact mode
                if (LS.get('compact') === 'true') {
                    const ct = document.getElementById('compactToggle');
                    if (ct) ct.checked = true;
                    document.documentElement.style.setProperty('--radius', '9px');
                }

                // 4. Font size
                const fs = LS.get('fontSize');
                if (fs && fs !== '100') {
                    const fr = document.getElementById('fontSizeRange');
                    if (fr) fr.value = fs;
                    const lbl = document.getElementById('fontSizeLbl');
                    if (lbl) lbl.textContent = fs + '%';
                    document.documentElement.style.fontSize = (parseFloat(fs) / 100) + 'rem';
                }

                // 5. Reduce motion
                if (LS.get('reduceMotion') === 'true') {
                    const rmt = document.getElementById('reduceMotionToggle');
                    if (rmt) rmt.checked = true;
                    let s = document.getElementById('reduceMotionStyle');
                    if (!s) {
                        s = document.createElement('style');
                        s.id = 'reduceMotionStyle';
                        document.head.appendChild(s);
                    }
                    s.textContent = '*, *::before, *::after { animation-duration: 0.01ms !important; animation-iteration-count: 1 !important; }';
                }

                // 6. Focus ring
                if (LS.get('focusRing') === 'true') {
                    const frt = document.getElementById('focusRingToggle');
                    if (frt) frt.checked = true;
                    let s = document.getElementById('focusRingStyle');
                    if (!s) {
                        s = document.createElement('style');
                        s.id = 'focusRingStyle';
                        document.head.appendChild(s);
                    }
                    s.textContent = '*:focus { outline: 3px solid var(--accent-maroon) !important; outline-offset: 3px !important; }';
                }

                // 7. Account profile fields
                const profileFields = ['fullname', 'dob', 'gender', 'nationality', 'email'];
                profileFields.forEach(key => {
                    const val = LS.get('prof_' + key);
                    if (!val) return;
                    const span = document.querySelector('[data-field="' + key + '"]');
                    const input = document.querySelector('[data-input="' + key + '"]');
                    if (span) {
                        span.textContent = val;
                        span.classList.remove('empty');
                    }
                    if (input) {
                        if (input.tagName === 'SELECT') {
                            for (let opt of input.options) {
                                if (opt.text === val || opt.value === val) {
                                    opt.selected = true;
                                    break;
                                }
                            }
                        } else {
                            input.value = val;
                        }
                    }
                });

                // 8. Notification read state
                const readIdxArr = LS.getJ('notifRead');
                if (readIdxArr && readIdxArr.length) {
                    const items = document.querySelectorAll('.notif-item');
                    let unread = 0;
                    items.forEach((item, i) => {
                        if (readIdxArr.includes(i)) {
                            item.classList.remove('unread');
                            const dot = item.querySelector('.unread-dot');
                            if (dot) dot.style.display = 'none';
                        } else if (item.classList.contains('unread')) {
                            unread++;
                        }
                    });
                    const uc = document.getElementById('unreadCount');
                    if (uc) uc.textContent = unread + ' unread';
                    if (unread === 0) document.querySelectorAll('.notif-badge').forEach(b => b.style.display = 'none');
                    else document.querySelectorAll('.notif-badge').forEach(b => {
                        b.style.display = '';
                        b.textContent = unread;
                    });
                }
            }

            /* ── DOM-only helpers (no save, used by restore + public fns) ── */
            function _applyThemeDOM(theme) {
                document.documentElement.setAttribute('data-theme', theme);
                // Remove any JS-set inline tint overrides so the new theme's
                // CSS variable values take over cleanly
                document.documentElement.style.removeProperty('--section-tint-start');
                document.documentElement.style.removeProperty('--section-tint-end');
                const tMap = {
                    'light': 'light',
                    'dark': 'dark',
                    'high-contrast': 'hc'
                };
                ['light', 'dark', 'hc'].forEach(k => {
                    const el = document.getElementById('tp-' + k);
                    const ch = document.getElementById('tc-' + k);
                    if (el) el.classList.remove('selected');
                    if (ch) ch.style.display = 'none';
                });
                const key = tMap[theme] || theme;
                const el = document.getElementById('tp-' + key);
                const ch = document.getElementById('tc-' + key);
                if (el) el.classList.add('selected');
                if (ch) ch.style.display = '';
            }

            function _applyAccentDOM(color, light) {
                document.querySelectorAll('.c-dot').forEach(d => d.classList.remove('selected'));
                const dot = document.querySelector('.c-dot[data-color="' + color + '"]');
                if (dot) dot.classList.add('selected');
                document.documentElement.style.setProperty('--accent-maroon', color);
                document.documentElement.style.setProperty('--accent-light', light);
                // Parse hex to rgb for the tint variables so the section header gradient
                // always uses the new accent color at the right opacity — never the old light pastel
                const r = parseInt(color.slice(1, 3), 16),
                    g = parseInt(color.slice(3, 5), 16),
                    b = parseInt(color.slice(5, 7), 16);
                const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
                const isHC = document.documentElement.getAttribute('data-theme') === 'high-contrast';
                const alpha = isHC ? 0.16 : isDark ? 0.13 : 0.09;
                document.documentElement.style.setProperty('--section-tint-start', `rgba(${r},${g},${b},${alpha})`);
                document.documentElement.style.setProperty('--section-tint-end', `rgba(${r},${g},${b},0)`);
            }

            /* ── Toast ─────────────────────────────────────────────────────────── */
            let toastTimer;

            function showToast(msg) {
                const t = document.getElementById('app-toast');
                if (!t) return;
                t.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;margin-right:6px;"><circle cx="12" cy="12" r="10"/><polyline points="9 12 11 14 15 10"/></svg> ' + msg;
                t.classList.add('show');
                clearTimeout(toastTimer);
                toastTimer = setTimeout(() => t.classList.remove('show'), 2800);
            }

            /* ── Browser Back/Forward Navigation ───────────────────────────────── */
            // We use a lightweight pushState approach: each tab switch or overlay open
            // pushes a state object onto the history stack. popstate restores the UI.
            // Reload always lands on Home because initPage() calls replaceState with
            // the home state and never reads the hash back on load.
            let _navSuppressed = false;

            function _pushNav(state) {
                if (_navSuppressed) return;
                const hash = '#' + state.type + '-' + state.value + (state.sub ? '-' + state.sub : '');
                history.pushState(state, '', hash);
            }

            function _restoreNav(state) {
                _navSuppressed = true;
                // Always close all overlays first
                document.querySelectorAll('.overlay-page.active').forEach(o => o.classList.remove('active'));
                if (!state || state.type === 'tab') {
                    const tab = (state && state.value) ? state.value : 'home';
                    _switchTabDOM(tab);
                    if (state && state.sub) switchLendingSub(state.sub);
                } else if (state.type === 'overlay') {
                    _switchTabDOM('home'); // ensure a tab is active behind overlay
                    _openOverlayDOM(state.value);
                }
                _navSuppressed = false;
            }

            window.addEventListener('popstate', function(e) {
                _restoreNav(e.state);
            });

            /* ── Profile Dropdown ──────────────────────────────────────────────── */
            function openDropdown() {
                document.getElementById('profileDropdown').classList.add('open');
                document.getElementById('avatarBtn').setAttribute('aria-expanded', 'true');
            }

            function closeDropdown() {
                document.getElementById('profileDropdown').classList.remove('open');
                document.getElementById('avatarBtn').setAttribute('aria-expanded', 'false');
            }

            function toggleDropdown() {
                document.getElementById('profileDropdown').classList.contains('open') ? closeDropdown() : openDropdown();
            }

            /* ── Overlays ──────────────────────────────────────────────────────── */

            function _openOverlayDOM(id) {
                closeDropdown();
                const el = document.getElementById(id);
                if (!el) return;
                el.classList.add('active');
                document.querySelectorAll('.overlay-page.active').forEach(o => {
                    if (o !== el) o.classList.remove('active');
                });
            }

            function openOverlay(id) {
                _pushNav({
                    type: 'overlay',
                    value: id
                });
                _openOverlayDOM(id);
            }

            function closeOverlay(id) {
                // Use history.back() so the browser's forward button also works.
                // We also immediately remove the class for instant visual feedback.
                const el = document.getElementById(id);
                if (el) el.classList.remove('active');
                history.back();
            }

            /* ── Main Tab Switcher ─────────────────────────────────────────────── */
            function _switchTabDOM(tabName) {
                const panel = document.getElementById('panel-' + tabName);
                if (panel) panel.classList.add('active');
                document.querySelectorAll('.nav-tab').forEach(b => b.classList.remove('active'));
                const btn = document.querySelector('.nav-tab[data-tab="' + tabName + '"]');
                if (btn) btn.classList.add('active');
                document.querySelectorAll('.tab-panel').forEach(p => {
                    if (p !== panel) p.classList.remove('active');
                });
            }

            function switchTab(tabName, sub) {
                _pushNav({
                    type: 'tab',
                    value: tabName,
                    sub: sub || null
                });
                _switchTabDOM(tabName);
            }

            /* ── Lending Sub-Sections ──────────────────────────────────────────── */
            function switchLendingSub(subName) {
                const sub = document.getElementById('lending-' + subName);
                if (sub) sub.classList.add('active');
                document.querySelectorAll('.lending-nav-btn').forEach(b => b.classList.remove('active'));
                const btn = document.querySelector('.lending-nav-btn[data-lending-nav="' + subName + '"]');
                if (btn) btn.classList.add('active');
                document.querySelectorAll('.lending-sub').forEach(s => {
                    if (s !== sub) s.classList.remove('active');
                });
            }

            /* ── Account Sub-Tabs ──────────────────────────────────────────────── */
            function switchAccTab(panelId) {
                const panel = document.getElementById(panelId);
                if (panel) panel.classList.add('active');
                document.querySelectorAll('.acc-nav-btn').forEach(b => b.classList.remove('active'));
                const btn = document.querySelector('.acc-nav-btn[data-acc-tab="' + panelId + '"]');
                if (btn) btn.classList.add('active');
                document.querySelectorAll('#accountOverlay .overlay-sub-panel').forEach(p => {
                    if (p !== panel) p.classList.remove('active');
                });
            }

            /* ── Settings Sub-Tabs ─────────────────────────────────────────────── */
            function switchSettTab(panelId) {
                const panel = document.getElementById(panelId);
                if (panel) panel.classList.add('active');
                document.querySelectorAll('.s-nav-item').forEach(b => b.classList.remove('active'));
                const btn = document.querySelector('.s-nav-item[data-sett-tab="' + panelId + '"]');
                if (btn) btn.classList.add('active');
                document.querySelectorAll('#settingsOverlay .overlay-sub-panel').forEach(p => {
                    if (p !== panel) p.classList.remove('active');
                });
            }

            /* ── Equipment Search/Filter ───────────────────────────────────────── */
            function filterEquipment() {
                const search = (document.getElementById('equipmentSearch').value || '').toLowerCase();
                const category = (document.getElementById('categoryFilter').value || '').toLowerCase();
                document.querySelectorAll('.item-node').forEach(item => {
                    const nameMatch = item.dataset.name.includes(search);
                    const catMatch = !category || item.dataset.category === category;
                    item.style.display = (nameMatch && catMatch) ? '' : 'none';
                });
            }

            /* ── Borrow Form ───────────────────────────────────────────────────── */
            function openBorrowForm(itemName) {
                document.getElementById('selectedItem').value = itemName;
                document.getElementById('selectedItemLabel').textContent = itemName;
                switchTab('lending', 'form');
                switchLendingSub('form');
            }

            /* ── Room Form ─────────────────────────────────────────────────────── */
            function openRoomForm(roomName) {
                document.getElementById('selectedRoomLabel').textContent = roomName;
                const sec = document.getElementById('room-form-section');
                if (sec) {
                    sec.classList.remove('hidden');
                    sec.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            }

            function closeRoomForm() {
                const sec = document.getElementById('room-form-section');
                if (sec) sec.classList.add('hidden');
            }

            /* ── Notifications ─────────────────────────────────────────────────── */
            function filterNotifs(cat) {
                document.querySelectorAll('.notif-tab').forEach(t => t.classList.remove('active'));
                const btn = document.querySelector('.notif-tab[data-notif-filter="' + cat + '"]');
                if (btn) btn.classList.add('active');
                document.querySelectorAll('.notif-item').forEach(item => {
                    if (cat === 'all') item.style.display = '';
                    else if (cat === 'unread') item.style.display = item.classList.contains('unread') ? '' : 'none';
                    else item.style.display = item.dataset.cat === cat ? '' : 'none';
                });
            }

            function markAllRead() {
                const readArr = [];
                document.querySelectorAll('.notif-item').forEach((item, i) => {
                    item.classList.remove('unread');
                    const dot = item.querySelector('.unread-dot');
                    if (dot) dot.style.display = 'none';
                    readArr.push(i);
                });
                const uc = document.getElementById('unreadCount');
                if (uc) uc.textContent = '0 unread';
                document.querySelectorAll('.notif-badge').forEach(b => b.style.display = 'none');
                LS.setJ('notifRead', readArr);
                showToast('All notifications marked as read.');
            }

            /* ── Settings: Theme ───────────────────────────────────────────────── */
            function applyTheme(theme) {
                _applyThemeDOM(theme);
                LS.set('theme', theme);
                showToast('Theme: ' + theme.charAt(0).toUpperCase() + theme.slice(1));
            }

            /* ── Settings: Accent Color ────────────────────────────────────────── */
            function applyAccent(color, light) {
                _applyAccentDOM(color, light);
                LS.set('accentColor', color);
                LS.set('accentLight', light);
                showToast('Accent color updated!');
            }

            /* ── Settings: Compact ─────────────────────────────────────────────── */
            function applyCompact(on) {
                document.documentElement.style.setProperty('--radius', on ? '9px' : '16px');
                LS.set('compact', on);
                showToast(on ? 'Compact mode enabled' : 'Compact mode disabled');
            }

            /* ── Settings: Font Size ───────────────────────────────────────────── */
            function applyFontSize(val) {
                const lbl = document.getElementById('fontSizeLbl');
                if (lbl) lbl.textContent = val + '%';
                document.documentElement.style.fontSize = (val / 100) + 'rem';
                LS.set('fontSize', val);
            }

            /* ── Settings: Reduce Motion ───────────────────────────────────────── */
            /* IMPORTANT: We only kill animation-duration here, NEVER touch
               `transition` on `*` — doing so was the root cause of the freeze bug
               because it would also null-out pointer-event related repaint cycles. */
            function applyReduceMotion(on) {
                let s = document.getElementById('reduceMotionStyle');
                if (!s) {
                    s = document.createElement('style');
                    s.id = 'reduceMotionStyle';
                    document.head.appendChild(s);
                }
                s.textContent = on ?
                    '*, *::before, *::after { animation-duration: 0.01ms !important; animation-iteration-count: 1 !important; }' :
                    '';
                LS.set('reduceMotion', on);
                showToast(on ? 'Animations disabled' : 'Animations re-enabled');
            }

            /* ── Settings: Focus Ring ──────────────────────────────────────────── */
            function applyFocusRing(on) {
                let s = document.getElementById('focusRingStyle');
                if (!s) {
                    s = document.createElement('style');
                    s.id = 'focusRingStyle';
                    document.head.appendChild(s);
                }
                s.textContent = on ?
                    '*:focus { outline: 3px solid var(--accent-maroon) !important; outline-offset: 3px !important; }' :
                    '';
                LS.set('focusRing', on);
                showToast(on ? 'Focus rings enhanced' : 'Focus rings reset');
            }

            /* ── Settings: Reset All ───────────────────────────────────────────── */
            function resetAllSettings() {
                applyTheme('light');
                const ct = document.getElementById('compactToggle');
                if (ct) {
                    ct.checked = false;
                    applyCompact(false);
                }
                const fr = document.getElementById('fontSizeRange');
                if (fr) {
                    fr.value = 100;
                    applyFontSize(100);
                }
                const rmt = document.getElementById('reduceMotionToggle');
                if (rmt) {
                    rmt.checked = false;
                    applyReduceMotion(false);
                }
                const frt = document.getElementById('focusRingToggle');
                if (frt) {
                    frt.checked = false;
                    applyFocusRing(false);
                }
                applyAccent('#600302', '#f3e5e6');
                // Clear persisted settings (but keep account + notif state)
                ['theme', 'accentColor', 'accentLight', 'compact', 'fontSize', 'reduceMotion', 'focusRing'].forEach(k => LS.del(k));
                showToast('All settings reset to defaults.');
            }

            /* ── Profile Edit ──────────────────────────────────────────────────── */
            function toggleProfileEdit() {
                const editBtn = document.getElementById('editProfileBtn');
                const saveBtn = document.getElementById('saveProfileBtn');
                const cancelBtn = document.getElementById('cancelProfileBtn');
                if (editBtn) editBtn.style.display = 'none';
                if (saveBtn) saveBtn.style.display = 'flex';
                if (cancelBtn) cancelBtn.style.display = 'flex';
                document.querySelectorAll('[data-field]').forEach(span => {
                    const key = span.dataset.field;
                    const input = document.querySelector('[data-input="' + key + '"]');
                    if (!input) return;
                    span.style.display = 'none';
                    input.style.display = '';
                    input.disabled = false;
                    if (span.classList.contains('empty')) input.value = '';
                });
            }

            function cancelProfileEdit() {
                const editBtn = document.getElementById('editProfileBtn');
                const saveBtn = document.getElementById('saveProfileBtn');
                const cancelBtn = document.getElementById('cancelProfileBtn');
                if (editBtn) editBtn.style.display = 'flex';
                if (saveBtn) saveBtn.style.display = 'none';
                if (cancelBtn) cancelBtn.style.display = 'none';
                document.querySelectorAll('[data-input]').forEach(input => {
                    const key = input.dataset.input;
                    const span = document.querySelector('[data-field="' + key + '"]');
                    if (!span) return;
                    span.style.display = '';
                    input.style.display = 'none';
                    input.disabled = true;
                });
            }

            function saveProfileEdit() {
                document.querySelectorAll('[data-input]').forEach(input => {
                    const key = input.dataset.input;
                    const span = document.querySelector('[data-field="' + key + '"]');
                    if (!span) return;
                    const val = input.tagName === 'SELECT' ?
                        input.options[input.selectedIndex].text :
                        input.value.trim();
                    if (val) {
                        span.textContent = val;
                        span.classList.remove('empty');
                        LS.set('prof_' + key, val);
                    } else {
                        span.textContent = '— Not provided';
                        span.classList.add('empty');
                        LS.del('prof_' + key);
                    }
                });
                cancelProfileEdit();
                showToast('Profile updated successfully!');
            }

            /* ── Requests Table — Client-Side Render ───────────────────────────── */
            let _reqCurrentFilter = 'All';
            let _reqSortOrder     = 'desc'; // desc = latest first

            function _escHtml(str) {
                if (!str) return '';
                return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
            }

            function _statusPill(status) {
                const map = {
                    'Waiting':  { cls:'status-waiting',  icon:'<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',   label:'Pending' },
                    'Approved': { cls:'status-approved', icon:'<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>',   label:'Approved' },
                    'Declined': { cls:'status-declined', icon:'<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',   label:'Declined' },
                    'Overdue':  { cls:'status-overdue',  icon:'<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',   label:'Overdue' },
                    'Returned': { cls:'status-returned', icon:'<polyline points="20 6 9 17 4 12"/>',   label:'Returned' },
                };
                const d = map[status] || map['Waiting'];
                const sa = `xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-right:5px;vertical-align:middle;"`;
                return `<span class="status-pill ${d.cls}"><svg ${sa}>${d.icon}</svg>${d.label}</span>`;
            }

            function renderRequestsTable() {
                const tbody = document.getElementById('requestsTbody');
                if (!tbody) return;
                const data = (window.REQUESTS_DATA || []).slice();

                // Sort
                data.sort((a, b) => {
                    const da = new Date(a.request_date || a.borrow_date || '2000-01-01');
                    const db = new Date(b.request_date || b.borrow_date || '2000-01-01');
                    return _reqSortOrder === 'desc' ? db - da : da - db;
                });

                // Filter
                const filtered = _reqCurrentFilter === 'All' ? data : data.filter(r => {
                    if (_reqCurrentFilter === 'Waiting') return r.status === 'Waiting';
                    return r.status === _reqCurrentFilter;
                });

                if (filtered.length === 0) {
                    tbody.innerHTML = `<tr><td colspan="8"><div class="table-empty"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="36" height="36" style="width:36px;height:36px;display:block;margin:0 auto 8px;opacity:0.7;"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1" ry="1"/></svg>No requests found for this filter.</div></td></tr>`;
                    return;
                }

                tbody.innerHTML = filtered.map(r => {
                    const canReturn = r.status === 'Approved' || r.status === 'Overdue';
                    const returnBtn = canReturn
                        ? `<button class="btn-return-item" data-action="return-item" data-id="${_escHtml(r.id)}" data-name="${_escHtml(r.equipment_name)}" title="Return this item">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="13" height="13" style="width:13px;height:13px;margin-right:4px;vertical-align:middle;"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-3.51"/></svg>Return
                           </button>`
                        : '—';
                    const noteCol = r.status === 'Declined' ? `<span style="font-size:0.8rem;color:var(--text-light);">${_escHtml(r.reason)}</span>`
                                  : r.status === 'Overdue' ? `<span style="font-size:0.8rem;color:#e65100;font-weight:600;">Past due: ${_escHtml(r.return_date)}</span>`
                                  : '—';
                    return `<tr class="${r.status === 'Overdue' ? 'row-overdue' : ''}">
                        <td><strong>${_escHtml(r.equipment_name)}</strong></td>
                        <td>${_escHtml(r.instructor)}</td>
                        <td>${_escHtml(r.room)}</td>
                        <td>${_escHtml(r.borrow_date)}</td>
                        <td>${_escHtml(r.return_date)}</td>
                        <td>${_statusPill(r.status)}</td>
                        <td>${noteCol}</td>
                        <td>${returnBtn}</td>
                    </tr>`;
                }).join('');
            }

            function setRequestsFilter(status) {
                _reqCurrentFilter = status;
                const dd = document.getElementById('reqStatusFilter');
                if (dd) dd.value = status === 'Waiting' ? 'Waiting' : status;
                renderRequestsTable();
            }

            function toggleReqSort() {
                _reqSortOrder = _reqSortOrder === 'desc' ? 'asc' : 'desc';
                const lbl = document.getElementById('reqSortLabel');
                const btn = document.getElementById('reqSortBtn');
                if (lbl) lbl.textContent = _reqSortOrder === 'desc' ? 'Latest First' : 'Oldest First';
                if (btn) {
                    const svg = btn.querySelector('svg');
                    if (svg) svg.style.transform = _reqSortOrder === 'asc' ? 'rotate(180deg)' : '';
                }
                renderRequestsTable();
            }

            function returnItem(reqId, itemName) {
                if (!confirm('Confirm return of "' + itemName + '"? This will update the inventory.')) return;
                const fd = new FormData();
                fd.append('action', 'return_item');
                fd.append('request_id', reqId);
                fetch(window.location.pathname, { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            // Update local data
                            const req = (window.REQUESTS_DATA || []).find(r => String(r.id) === String(reqId));
                            if (req) req.status = 'Returned';
                            renderRequestsTable();
                            showToast(data.msg || 'Item returned successfully!');
                            // Update overdue count display if needed
                            checkOverdueState();
                        } else {
                            showToast('Error: ' + (data.msg || 'Could not return item.'));
                        }
                    })
                    .catch(() => showToast('Network error. Please try again.'));
            }

            function checkOverdueState() {
                const overdueCount = (window.REQUESTS_DATA || []).filter(r => r.status === 'Overdue').length;
                // Update overdue stat value
                const statEl = document.getElementById('statOverdueVal');
                if (statEl) statEl.textContent = overdueCount;
                // Show/hide overdue alert
                const alertEl = document.getElementById('overdue-alert');
                if (alertEl) alertEl.style.display = overdueCount > 0 ? '' : 'none';
                // Update notification badges
                const baseUnread = 3 + overdueCount;
                document.querySelectorAll('.notif-badge').forEach(b => {
                    if (overdueCount > 0) { b.style.display = ''; b.textContent = baseUnread; }
                });
            }

            /* ── Borrow Form Init ──────────────────────────────────────────────── */
            function initBorrowForm() {
                const form = document.getElementById('borrowForm');
                const borrowInp = document.getElementById('borrow_date');
                const returnInp = document.getElementById('return_date');
                const instrInp = document.getElementById('instructorField');
                if (!form || !borrowInp || !returnInp) return;

                borrowInp.min = todayStr;
                returnInp.min = todayStr;

                borrowInp.addEventListener('change', function() {
                    returnInp.min = this.value;
                    if (returnInp.value && returnInp.value < this.value) returnInp.value = this.value;
                });

                if (instrInp) {
                    instrInp.addEventListener('input', function() {
                        this.value = this.value.replace(/[^a-zA-Z\s.']/g, '');
                    });
                }

                form.addEventListener('submit', function(e) {
                    const bv = borrowInp.value;
                    const rv = returnInp.value;
                    if (bv < todayStr) {
                        e.preventDefault();
                        alert('The borrow date cannot be in the past.');
                        return;
                    }
                    if (rv < bv) {
                        e.preventDefault();
                        alert('The return date cannot be earlier than the borrow date.');
                        return;
                    }
                    e.preventDefault();
                    document.getElementById('loading-overlay').classList.add('active');
                    const hidden = document.createElement('input');
                    hidden.type = 'hidden';
                    hidden.name = 'borrow_submit';
                    hidden.value = '1';
                    this.appendChild(hidden);
                    setTimeout(() => this.submit(), 2000);
                });
            }

            /* ════════════════════════════════════════════════════════════════════
               MASTER EVENT DELEGATION
               All click-based interactions route through here. Each case is
               wrapped in a try-catch so one failing action can NEVER freeze
               the rest of the UI — this was the secondary cause of the freeze.
            ════════════════════════════════════════════════════════════════════ */
            document.addEventListener('click', function(e) {
                const el = e.target.closest('[data-action]');
                if (!el) return;
                const action = el.dataset.action;
                try {
                    switch (action) {
                        case 'open-overlay':
                            openOverlay(el.dataset.target);
                            break;
                        case 'close-overlay':
                            closeOverlay(el.dataset.target);
                            break;
                        case 'dismiss-alert': {
                            const t = document.getElementById(el.dataset.target);
                            if (t) t.style.display = 'none';
                            break;
                        }
                        case 'filter-requests':
                            // From stat card click — go to My Requests tab with filter
                            switchTab('lending', 'requests');
                            switchLendingSub('requests');
                            setRequestsFilter(el.dataset.status);
                            break;
                        case 'filter-requests-dd':
                            setRequestsFilter(el.value);
                            break;
                        case 'toggle-sort':
                            toggleReqSort();
                            break;
                        case 'return-item':
                            returnItem(el.dataset.id, el.dataset.name);
                            break;
                        case 'go-tab':
                            switchTab(el.dataset.tab, el.dataset.lending || null);
                            if (el.dataset.lending) switchLendingSub(el.dataset.lending);
                            break;
                        case 'open-borrow-form':
                            openBorrowForm(el.dataset.item);
                            break;
                        case 'lending-back':
                            switchLendingSub('browse');
                            break;
                        case 'open-room-form':
                            openRoomForm(el.dataset.room);
                            break;
                        case 'close-room-form':
                            closeRoomForm();
                            break;
                        case 'room-reserve-preview':
                            showToast('Room Reservation feature coming soon!');
                            break;
                        case 'apply-theme':
                            applyTheme(el.dataset.theme);
                            break;
                        case 'apply-accent':
                            applyAccent(el.dataset.color, el.dataset.light);
                            break;
                        case 'reset-settings':
                            resetAllSettings();
                            break;
                        case 'profile-edit':
                            toggleProfileEdit();
                            break;
                        case 'profile-save':
                            saveProfileEdit();
                            break;
                        case 'profile-cancel':
                            cancelProfileEdit();
                            break;
                        case 'mark-all-read':
                            markAllRead();
                            break;
                        case 'toast':
                            showToast(el.dataset.msg || '');
                            break;
                        case 'logout':
                            closeDropdown();
                            if (confirm('Confirm Logout?')) window.location.href = 'includes/logout.php';
                            break;
                    }
                } catch (err) {
                    console.warn('Action "' + action + '" failed:', err);
                }
            });

            /* ── Avatar button ────────────────────────────────────────────────── */
            const avatarBtn = document.getElementById('avatarBtn');
            if (avatarBtn) {
                avatarBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    toggleDropdown();
                });
            }

            /* ── Close dropdown on outside click ─────────────────────────────── */
            document.addEventListener('click', function(e) {
                if (!e.target.closest('.header-right')) closeDropdown();
            });

            /* ── Nav tabs ─────────────────────────────────────────────────────── */
            document.querySelectorAll('.nav-tab').forEach(btn => {
                btn.addEventListener('click', function() {
                    switchTab(this.dataset.tab);
                });
            });

            /* ── Lending sub-nav ──────────────────────────────────────────────── */
            document.querySelectorAll('.lending-nav-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    switchLendingSub(this.dataset.lendingNav);
                });
            });

            /* ── Account sub-nav ──────────────────────────────────────────────── */
            document.querySelectorAll('.acc-nav-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    switchAccTab(this.dataset.accTab);
                });
            });

            /* ── Settings sub-nav ─────────────────────────────────────────────── */
            document.querySelectorAll('.s-nav-item').forEach(btn => {
                btn.addEventListener('click', function() {
                    switchSettTab(this.dataset.settTab);
                });
            });

            /* ── Notification filter tabs ─────────────────────────────────────── */
            document.querySelectorAll('.notif-tab').forEach(btn => {
                btn.addEventListener('click', function() {
                    filterNotifs(this.dataset.notifFilter);
                });
            });

            /* ── Requests status filter dropdown ──────────────────────────────── */
            const reqStatusFilter = document.getElementById('reqStatusFilter');
            if (reqStatusFilter) reqStatusFilter.addEventListener('change', function() {
                setRequestsFilter(this.value);
            });

            /* ── Equipment search/filter ──────────────────────────────────────── */
            const eqSearch = document.getElementById('equipmentSearch');
            const eqCat = document.getElementById('categoryFilter');
            if (eqSearch) eqSearch.addEventListener('input', filterEquipment);
            if (eqCat) eqCat.addEventListener('change', filterEquipment);

            /* ── Settings toggles — use 'change' event (reliable, no delegation conflict) */
            const compactToggle = document.getElementById('compactToggle');
            if (compactToggle) compactToggle.addEventListener('change', function() {
                applyCompact(this.checked);
            });

            const fontSizeRange = document.getElementById('fontSizeRange');
            if (fontSizeRange) fontSizeRange.addEventListener('input', function() {
                applyFontSize(this.value);
            });

            const reduceMotionToggle = document.getElementById('reduceMotionToggle');
            if (reduceMotionToggle) reduceMotionToggle.addEventListener('change', function() {
                applyReduceMotion(this.checked);
            });

            const focusRingToggle = document.getElementById('focusRingToggle');
            if (focusRingToggle) focusRingToggle.addEventListener('change', function() {
                applyFocusRing(this.checked);
            });

            /* ── Page Init ────────────────────────────────────────────────────── */
            function initPage() {
                // Restore settings, account edits, and notification state from localStorage
                // (called before URL/slug logic so themes apply before first paint)
                restorePersistedState();

                // URL slug
                const userSlug = '<?php echo $user_slug; ?>';
                if (!window.location.search.includes(userSlug)) {
                    const newUrl = window.location.protocol + '//' + window.location.host +
                        window.location.pathname + '?u=' + userSlug;
                    window.history.replaceState({
                        type: 'tab',
                        value: 'home',
                        sub: null
                    }, '', newUrl);
                } else {
                    // Stamp the initial home state so popstate has something to land on
                    window.history.replaceState({
                        type: 'tab',
                        value: 'home',
                        sub: null
                    }, '', window.location.href.split('#')[0]);
                }
                // Auto-hide success alert + clean URL param
                const sa = document.getElementById('success-alert');
                if (sa) {
                    const url = new URL(window.location);
                    url.searchParams.delete('success');
                    window.history.replaceState({
                        type: 'tab',
                        value: 'home',
                        sub: null
                    }, document.title, url.pathname + (url.search || ''));
                    setTimeout(() => {
                        if (sa) sa.style.display = 'none';
                    }, 5000);
                }
                initBorrowForm();
                renderRequestsTable();
                checkOverdueState();
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initPage);
            } else {
                initPage();
            }

        })();
    </script>

</body>

</html>