<?php
// Admindash.php
session_start();
if (!isset($_SESSION['admin']) || $_SESSION['admin'] !== true) {
    header("Location: landing-page.php");
    exit();
}

$conn = mysqli_connect("localhost", "root", "", "lending_db");
if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

// ================= AUTO DECLINE EXPIRED REQUESTS =================

$today = date('Y-m-d');
$reason_expired = "Request expired – borrow date has already passed";

$stmt_expired = $conn->prepare("
    UPDATE tbl_requests
    SET status = 'Declined', reason = ?
    WHERE status = 'Waiting'
    AND borrow_date < ?
");
$stmt_expired->bind_param("ss", $reason_expired, $today);
$stmt_expired->execute();


// Handle Approve / Decline actions 
if (isset($_GET['action'], $_GET['id'])) {
    $request_id = (int) $_GET['id'];
    $action = $_GET['action'];

    if ($action === 'approve') {

    // Get equipment name
    $stmt_req = $conn->prepare("
        SELECT equipment_name 
        FROM tbl_requests 
        WHERE id = ?
    ");
    $stmt_req->bind_param("i", $request_id);
    $stmt_req->execute();
    $req_result = $stmt_req->get_result();
    $request = $req_result->fetch_assoc();

    if (!$request) {
        header("Location: admin-dashboard.php");
        exit();
    }

    $equipment_name = $request['equipment_name'];

    // Get ORIGINAL stock quantity (before any deductions)
    // We use a separate column or track it differently. For now, we'll query
    // the inventory table but understand that quantity has already been decremented.
    // The safest approach: count how many approved requests exist for this item,
    // then check if we can add one more.
    
    $stmt_stock = $conn->prepare("
        SELECT quantity 
        FROM tbl_inventory 
        WHERE item_name = ?
    ");
    $stmt_stock->bind_param("s", $equipment_name);
    $stmt_stock->execute();
    $stock_result = $stmt_stock->get_result();
    $stock = $stock_result->fetch_assoc();

    if (!$stock) {
        header("Location: admin-dashboard.php");
        exit();
    }

    $current_quantity = (int)$stock['quantity'];

    // Count already-approved requests for this item
    $stmt_count = $conn->prepare("
        SELECT COUNT(*) AS approved_count
        FROM tbl_requests
        WHERE equipment_name = ?
        AND status = 'Approved'
    ");
    $stmt_count->bind_param("s", $equipment_name);
    $stmt_count->execute();
    $count_result = $stmt_count->get_result();
    $count_data = $count_result->fetch_assoc();

    $approved_count = (int)$count_data['approved_count'];

    // Check if we have stock left to approve this request
    // The current_quantity is what remains. If it's > 0, we can approve.
    if ($current_quantity > 0) {

        // Approve this request
        $stmt_approve = $conn->prepare("
            UPDATE tbl_requests 
            SET status = 'Approved', reason = NULL 
            WHERE id = ?
        ");
        $stmt_approve->bind_param("i", $request_id);
        $stmt_approve->execute();

        // Deduct stock
        $stmt_deduct = $conn->prepare("
            UPDATE tbl_inventory 
            SET quantity = quantity - 1
            WHERE item_name = ?
        ");
        $stmt_deduct->bind_param("s", $equipment_name);
        $stmt_deduct->execute();

        // AUTO-DECLINE remaining WAITING requests if stock is now depleted
        if (($current_quantity - 1) <= 0) {

            $reason = "Out of stock – maximum approved requests reached";

            $stmt_auto_decline = $conn->prepare("
                UPDATE tbl_requests
                SET status = 'Declined', reason = ?
                WHERE equipment_name = ?
                AND status = 'Waiting'
            ");
            $stmt_auto_decline->bind_param("ss", $reason, $equipment_name);
            $stmt_auto_decline->execute();
        }

    } else {

        // No stock left → decline this request
        $reason = "Out of stock – maximum approved requests reached";

        $stmt_decline = $conn->prepare("
            UPDATE tbl_requests 
            SET status = 'Declined', reason = ?
            WHERE id = ?
        ");
        $stmt_decline->bind_param("si", $reason, $request_id);
        $stmt_decline->execute();
    }

    header("Location: admin-dashboard.php");
    exit();
} elseif ($action === 'decline') {
        $stmt = $conn->prepare("UPDATE tbl_requests SET status = 'Declined' WHERE id = ?");
        $stmt->bind_param("i", $request_id);
        $stmt->execute();
        header("Location: admin-dashboard.php#sec-declined");
        exit();
    }
}



// Add item
if (isset($_POST['add_item'])) {

    // Sanitize and format basic inputs
    $name = trim($_POST['item_name']);
    $category = $_POST['category'];
    $qty = (int) $_POST['quantity'];

    // Default image path
    $image_path = "uploads/default.png";

    // Handle Image upload
    if (!empty($_FILES['item_image']['name'])) {
        $allowed_types = ['image/jpeg', 'image/png', 'image/webp'];
        $file_type = $_FILES['item_image']['type'];

        if (!in_array($file_type, $allowed_types)) {
            die("Only JPG, PNG, and WEBP images are allowed.");
        }

        $max_size = 2 * 1024 * 1024;
        if ($_FILES['item_image']['size'] > $max_size) {
            die("Image too large. Maximum size is 2MB.");
        }

        $image_name = time() . "_" . preg_replace("/[^a-zA-Z0-9.]/", "_", $_FILES['item_image']['name']);
        $target = "uploads/" . $image_name;

        if (move_uploaded_file($_FILES['item_image']['tmp_name'], $target)) {
            $image_path = $target;
        }
    }

    // --- SECURE DATABASE INSERT ---
    $stmt = $conn->prepare("INSERT INTO tbl_inventory (item_name, category, quantity, image_path) VALUES (?, ?, ?, ?)");

    $stmt->bind_param("ssis", $name, $category, $qty, $image_path);

    if ($stmt->execute()) {
        $stmt->close();
        header("Location: admin-dashboard.php#sec-inventory");
        exit();
    } else {
        die("Error saving to database: " . $conn->error);
    }
}

//Edit Item
if (isset($_POST['update_item'])) {

    $item_id = intval($_POST['item_id']);
    $name = $_POST['item_name'];
    $category = $_POST['category'];
    $qty = intval($_POST['quantity']);

    $image_path = $_POST['old_image'];

    // Upload new image if provided
    if (!empty($_FILES['item_image']['name'])) {

        $allowed_types = ['image/jpeg', 'image/png', 'image/webp'];
        $file_type = $_FILES['item_image']['type'];

        if (!in_array($file_type, $allowed_types)) {
            die("Only JPG, PNG, and WEBP images are allowed.");
        }

        $max_size = 2 * 1024 * 1024;
        if ($_FILES['item_image']['size'] > $max_size) {
            die("Image too large. Maximum size is 2MB.");
        }

        $image_name = time() . "_" . $_FILES['item_image']['name'];
        $target = "uploads/" . $image_name;

        move_uploaded_file($_FILES['item_image']['tmp_name'], $target);
        $image_path = $target;
    }

    $sql = "UPDATE tbl_inventory 
            SET item_name='$name',
                category='$category',
                quantity=$qty,
                image_path='$image_path'
            WHERE item_id=$item_id";

    mysqli_query($conn, $sql);

    header("Location: admin-dashboard.php#sec-inventory");
    exit();
}

// Delete Item
if (isset($_GET['delete_item'])) {
    $id = intval($_GET['delete_item']);

    $res = mysqli_query($conn, "SELECT image_path FROM tbl_inventory WHERE item_id=$id");
    $row = mysqli_fetch_assoc($res);

    if ($row && $row['image_path'] !== 'uploads/default.png') {
        unlink($row['image_path']);
    }

    $stmt = mysqli_prepare($conn, "UPDATE tbl_inventory SET is_archived = 1 WHERE item_id = ?");
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    header("Location: admin-dashboard.php#sec-inventory");
    exit();
}
// restore
if (isset($_GET['restore_item'])) {
    $item_id = intval($_GET['restore_item']);

    $stmt = mysqli_prepare($conn, "UPDATE tbl_inventory SET is_archived = 0 WHERE item_id = ?");
    mysqli_stmt_bind_param($stmt, "i", $item_id);
    mysqli_stmt_execute($stmt);

    header("Location: admin-dashboard.php?view=archive");
    exit();
}
// permanently delete
if (isset($_GET['force_delete'])) {
    $item_id = intval($_GET['force_delete']);

    $stmt = mysqli_prepare($conn, "DELETE FROM tbl_inventory WHERE item_id = ?");
    mysqli_stmt_bind_param($stmt, "i", $item_id);
    mysqli_stmt_execute($stmt);

    header("Location: admin-dashboard.php?view=archive");
    exit();
}



// Fetch all requests
// Search Function for every section in admin-dashboard
//       (waiting-list, inventory, approved, declined, raw data )
$waiting_sql = "SELECT * FROM tbl_requests WHERE status='Waiting'";

if (!empty($_GET['waiting_search'])) {
    $search = "%" . $_GET['waiting_search'] . "%";
    $waiting_sql .= " AND (
        student_id LIKE ?
        OR student_name LIKE ?
        OR equipment_name LIKE ?
    ) ORDER BY request_date DESC";

    $stmt = $conn->prepare($waiting_sql);
    $stmt->bind_param("sss", $search, $search, $search);
    $stmt->execute();
    $waiting_result = $stmt->get_result();
} else {
    $waiting_sql .= " ORDER BY request_date DESC";
    $waiting_result = mysqli_query($conn, $waiting_sql);
}


$approved_sql = "SELECT * FROM tbl_requests WHERE status='Approved'";
if (!empty($_GET['approved_search'])) {
    $search = "%" . $_GET['approved_search'] . "%";
    $approved_sql .= " AND (
        student_id LIKE ?
        OR student_name LIKE ?
        OR equipment_name LIKE ?
    ) ORDER BY request_date DESC";
    $stmt = $conn->prepare($approved_sql);
    $stmt->bind_param("sss", $search, $search, $search);
    $stmt->execute();
    $approved_result = $stmt->get_result();
} else {
    $approved_sql .= " ORDER BY request_date DESC";
    $approved_result = mysqli_query($conn, $approved_sql);
}

$declined_sql = "SELECT * FROM tbl_requests WHERE status='Declined'";
if (!empty($_GET['declined_search'])) {
    $search = "%" . $_GET['declined_search'] . "%";
    $declined_sql .= " AND (
        student_id LIKE ?
        OR student_name LIKE ?
        OR equipment_name LIKE ?
    ) ORDER BY request_date DESC";
    $stmt = $conn->prepare($declined_sql);
    $stmt->bind_param("sss", $search, $search, $search);
    $stmt->execute();
    $declined_result = $stmt->get_result();
} else {
    $declined_sql .= " ORDER BY request_date DESC";
    $declined_result = mysqli_query($conn, $declined_sql);
}

$inventory_sql = "SELECT * FROM tbl_inventory WHERE is_archived = 0";

if (!empty($_GET['inventory_search'])) {
    $search = "%" . $_GET['inventory_search'] . "%";
    $inventory_sql .= "
        AND (item_name LIKE ? OR category LIKE ?) 
        ORDER BY created_at DESC
    ";
    $stmt = $conn->prepare($inventory_sql);
    $stmt->bind_param("ss", $search, $search);
    $stmt->execute();
    $inventory_result = $stmt->get_result();
} else {
    $inventory_sql .= " ORDER BY created_at DESC";
    $inventory_result = mysqli_query($conn, $inventory_sql);
}

// archive
$archive_sql = "
    SELECT * FROM tbl_inventory
    WHERE is_archived = 1
    ORDER BY item_name ASC
";
$archive_result = mysqli_query($conn, $archive_sql);



$raw_data_sql = "SELECT student_id, student_name, equipment_name, instructor, room, borrow_date, return_date, request_date FROM tbl_requests";

if (!empty($_GET['raw_search'])) {
    $search = "%" . $_GET['raw_search'] . "%";
    $raw_data_sql .= " WHERE student_name LIKE ? OR equipment_name LIKE ? OR student_id LIKE ? ORDER BY request_date DESC";
    $stmt = $conn->prepare($raw_data_sql);
    $stmt->bind_param("sss", $search, $search, $search);
    $stmt->execute();
    $raw_data_result = $stmt->get_result();
} else {
    $raw_data_sql .= " ORDER BY request_date DESC";
    $raw_data_result = mysqli_query($conn, $raw_data_sql);
}

$edit_item = null;

if (isset($_GET['edit_item'])) {
    $edit_id = intval($_GET['edit_item']);
    $edit_query = mysqli_query($conn, "SELECT * FROM tbl_inventory WHERE item_id=$edit_id");
    $edit_item = mysqli_fetch_assoc($edit_query);
}

?>