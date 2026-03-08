<?php require_once __DIR__ . '/includes/admin-dashboard-functions.php'; ?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - EquipLend</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">

    <link rel="stylesheet" href="css/admin-dashboard.css">
</head>

<body>
    <div class="overlay" id="ui-overlay"></div>
    <nav class="navbar navbar-dark fixed-top">
        <div class="container-fluid justify-content-start"> <button class="btn btn-outline-light border-0 shadow-none"
                onclick="toggleSidebar()">
                <i class="bi bi-list fs-2"></i>
            </button>
            <span class="navbar-brand fw-bold ms-2">
                EQUIPLEND <span class="fw-light opacity-75">ADMIN</span>
            </span>
        </div>
    </nav>

    <div id="sidebar">
        <div class="nav-label">Management</div>

        <button type="button" onclick="showSection('archive')" id="link-archive" class="sidebar-btn">
            <i class="bi bi-archive"></i> Archive
        </button>

        <button type="button" onclick="showSection('waiting')" id="link-waiting" class="sidebar-btn active">
            <i class="bi bi-people"></i> Waiting List
        </button>

        <button type="button" onclick="showSection('inventory')" id="link-inventory" class="sidebar-btn">
            <i class="bi bi-box-seam"></i> Inventory
        </button>

        <div class="nav-label">Approvals</div>

        <button type="button" onclick="showSection('approved')" id="link-approved" class="sidebar-btn">
            <i class="bi bi-patch-check"></i> Approved
        </button>

        <button type="button" onclick="showSection('declined')" id="link-declined" class="sidebar-btn">
            <i class="bi bi-x-octagon"></i> Declined
        </button>

        <div class="nav-label">Records</div>

        <button type="button" onclick="showSection('raw-data')" id="link-raw-data" class="sidebar-btn">
            <i class="bi bi-database-fill"></i> Raw Data
        </button>

        <div class="sidebar-footer">
            <button type="button" class="sidebar-btn border-0" onclick="handleLogout()">
                <i class="bi bi-box-arrow-right"></i> Logout
            </button>
        </div>
    </div>

    <div class="container">
        <div class="main-container">

            <!-- WAITING LIST SECTION -->
            <div id="sec-waiting" class="view-section active">
                <h4 class="fw-bold mb-4 text-maroon">
                    <i class="bi bi-people me-2"></i>
                    Student Waiting List
                </h4>
                <form method="GET" action="admin-dashboard.php#sec-waiting" class="mb-3">
                    <input type="hidden" name="view" value="waiting">

                    <div class="input-group">
                        <input type="text" 
                               name="waiting_search"
                               id="waitingSearch" 
                               class="form-control"
                               placeholder="Search by Student ID, Name or Item"
                               value="<?= $_GET['waiting_search'] ?? '' ?>">
                    </div>
                </form>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-dark">
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Requested Item</th>
                                <th>Borrow Date</th>
                                <th>Return Date</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>

                        <tbody id="waiting-body">
                            <?php if (mysqli_num_rows($waiting_result) === 0) { ?>
                                <tr>
                                    <td colspan="7" class="text-center text-muted py-5"> <i
                                            class="bi bi-inbox fs-1 d-block mb-2"></i>
                                        No students are currently in the waiting list.
                                    </td>
                                </tr>
                            <?php } else { ?>
                                <?php while ($row = mysqli_fetch_assoc($waiting_result)) { ?>
                                    <tr>
                                        <td><?php echo $row['student_id']; ?></td>
                                        <td class="fw-bold"><?php echo htmlspecialchars($row['student_name']); ?></td>
                                        <td><?php echo htmlspecialchars($row['equipment_name']); ?></td>

                                        <td class="<?php
                                        $isPast = strtotime($row['borrow_date']) < strtotime(date('Y-m-d'));
                                        echo $isPast ? 'text-danger fw-bold' : '';
                                        ?>">
                                            <?php echo date('M d, Y', strtotime($row['borrow_date'])); ?>
                                            <?php if ($isPast): ?>
                                                <small class="d-block" style="font-size: 0.7rem;">(Date Passed)</small>
                                            <?php endif; ?>
                                        </td>

                                        <td>
                                            <?php echo date('M d, Y', strtotime($row['return_date'])); ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-warning text-dark px-3 py-2">
                                                <?php echo $row['status']; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <a href="admin-dashboard.php?action=approve&id=<?php echo $row['id']; ?>"
                                                class="btn btn-success btn-sm btn-circle-sm">
                                                <i class="bi bi-check-lg"></i>
                                            </a>
                                            <a href="admin-dashboard.php?action=decline&id=<?php echo $row['id']; ?>"
                                                class="btn btn-danger btn-sm btn-circle-sm ms-1">
                                                <i class="bi bi-x-lg"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php } ?>
                            <?php } ?>
                        </tbody>

                    </table>
                </div>
            </div>

            <div id="sec-inventory" class="view-section">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h4 class="fw-bold m-0 text-maroon"><i class="bi bi-box-seam me-2"></i>Equipment Inventory</h4>
                    <button class="btn btn-primary rounded-pill px-4 shadow-sm" data-bs-toggle="modal"
                        data-bs-target="#itemModal" onclick="prepareAdd()">
                        <i class="bi bi-plus-lg me-1"></i> Add Item
                    </button>
                </div>
                <form method="GET" action="admin-dashboard.php#sec-inventory" class="mb-3">
                    <input type="hidden" name="view" value="inventory">

                    <div class="input-group">
                        <input type="text" 
                               name="inventory_search" 
                               id="inventorySearch" 
                               class="form-control"
                               placeholder="Search by Item Name or Category"
                               value="<?= $_GET['inventory_search'] ?? '' ?>">
                    </div>
                </form>

                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-dark">
                            <tr>
                                <th>Photo</th>
                                <th>Item Name</th>
                                <th>Category</th>
                                <th>Qty</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="inventory-body">
                            <?php if (mysqli_num_rows($inventory_result) == 0) { ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-5">
                                        Inventory is empty.
                                    </td>
                                </tr>
                            <?php } else { ?>
                                <?php while ($item = mysqli_fetch_assoc($inventory_result)) { ?>
                                    <tr>
                                        <td>
                                            <img src="<?php echo $item['image_path']; ?>" class="item-img shadow-sm">
                                        </td>

                                        <td class="fw-bold text-maroon">
                                            <?php echo htmlspecialchars($item['item_name']); ?>
                                        </td>

                                        <td>
                                            <?php echo htmlspecialchars($item['category']); ?>
                                        </td>

                                        <td>
                                            <span class="badge bg-info text-dark">
                                                <?php echo $item['quantity']; ?> units
                                            </span>
                                        </td>

                                        <td>
                                            <?php if ($item['quantity'] > 0) { ?>
                                                <span class="badge bg-success">Available</span>
                                            <?php } else { ?>
                                                <span class="badge bg-danger">No Stock</span>
                                            <?php } ?>
                                        </td>

                                        <td>
                                            <a href="admin-dashboard.php?edit_item=<?php echo $item['item_id']; ?>#sec-inventory"
                                                class="btn btn-sm btn-outline-primary">
                                                <i class="bi bi-pencil"></i>
                                            </a>

                                            <!-- DELETE -->
                                            <a href="admin-dashboard.php?delete_item=<?php echo $item['item_id']; ?>"
                                                class="btn btn-sm btn-outline-danger">
                                                <i class="bi bi-trash"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php } ?>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <!-- ARCHIVE SECTION -->
             <div id="sec-archive" class="view-section">
    <h4 class="fw-bold mb-4 text-secondary">
        <i class="bi bi-archive me-2"></i> Archived Equipment
    </h4>

    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead class="table-dark">
                <tr>
                    <th>Photo</th>
                    <th>Item Name</th>
                    <th>Category</th>
                    <th>Qty</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (mysqli_num_rows($archive_result) == 0) { ?>
                    <tr>
                        <td colspan="5" class="text-center text-muted py-5">
                            No archived equipment.
                        </td>
                    </tr>
                <?php } else { ?>
                    <?php while ($item = mysqli_fetch_assoc($archive_result)) { ?>
                        <tr>
                            <td>
                                <img src="<?php echo $item['image_path']; ?>" class="item-img shadow-sm">
                            </td>

                            <td class="fw-bold text-muted">
                                <?php echo htmlspecialchars($item['item_name']); ?>
                            </td>

                            <td><?php echo htmlspecialchars($item['category']); ?></td>

                            <td>
                                <span class="badge bg-secondary">
                                    <?php echo $item['quantity']; ?> units
                                </span>
                            </td>

                            <td>
                                <!-- RESTORE -->
                                <a href="admin-dashboard.php?restore_item=<?php echo $item['item_id']; ?>"
                                   class="btn btn-sm btn-outline-success"
                                   onclick="return confirm('Are you sure you want to restore this item?');">
                                    <i class="bi bi-arrow-counterclockwise"></i>
                                </a>

                                <!-- PERMANENT DELETE -->
                                <a href="admin-dashboard.php?force_delete=<?php echo $item['item_id']; ?>"
                                   class="btn btn-sm btn-outline-danger"
                                   onclick="return confirm('Permanently delete this item?');">
                                    <i class="bi bi-trash"></i>
                                </a>
                            </td>
                        </tr>
                    <?php } ?>
                <?php } ?>
            </tbody>
        </table>
    </div>
</div>

            <!-- APPROVED SECTION -->
            <div id="sec-approved" class="view-section">
                <h4 class="fw-bold mb-4 text-success"><i class="bi bi-patch-check me-2"></i>Approved Requests</h4>
                <form method="GET" action="admin-dashboard.php#sec-approved" class="mb-3">
                    <input type="hidden" name="view" value="approved">

                    <div class="input-group">
                        <input type="text" 
                               name="approved_search"
                               id="approvedSearch"  
                               class="form-control"
                               placeholder="Search by ID, Name, or Item..."
                               value="<?= $_GET['approved_search'] ?? '' ?>">
                    </div>
                </form>

                <div class="table-responsive">
                    <table class="table table-striped align-middle">
                        <thead class="table-dark">
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Item</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody id="approved-list">
                            <?php if (mysqli_num_rows($approved_result) === 0) { ?>
                                <tr>
                                    <td colspan="4" class="text-center text-muted py-5">
                                        <i class="bi bi-check-circle fs-1 d-block mb-2 text-success"></i>
                                        No approved requests yet.
                                    </td>
                                </tr>
                            <?php } else { ?>
                                <?php while ($row = mysqli_fetch_assoc($approved_result)) { ?>
                                    <tr>
                                        <td><?php echo $row['student_id']; ?></td>
                                        <td class="fw-bold"><?php echo $row['student_name']; ?></td>
                                        <td><?php echo $row['equipment_name']; ?></td>
                                        <td>
                                            <span class="badge bg-success">
                                                <?php echo $row['status']; ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php } ?>
                            <?php } ?>
                        </tbody>

                    </table>
                </div>
            </div>

            <!-- DECLINED SECTION -->
            <div id="sec-declined" class="view-section">
                <h4 class="fw-bold mb-4 text-danger"><i class="bi bi-x-octagon me-2"></i>Declined Requests</h4>
                <form method="GET" action="admin-dashboard.php#sec-declined" class="mb-3">
                    <input type="hidden" name="view" value="declined">

                    <div class="input-group">
                        <input type="text" 
                               name="declined_search" 
                               id="declinedSearch" 
                               class="form-control"
                               placeholder="Search by ID, Name, or Item..."
                               value="<?= $_GET['declined_search'] ?? '' ?>">
                    </div>
                </form>

                <div class="table-responsive">
                    <table class="table table-striped align-middle">
                        <thead class="table-dark">
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Item</th>
                                <th>Status</th>
                                <th>Reason</th>
                            </tr>
                        </thead>
                        <tbody id="declined-list">
                            <?php if (mysqli_num_rows($declined_result) === 0) { ?>
                                <tr>
                                    <td colspan="4" class="text-center text-muted py-5">
                                        <i class="bi bi-x-circle fs-1 d-block mb-2 text-danger"></i>
                                        No declined requests.
                                    </td>
                                </tr>
                            <?php } else { ?>
                                <?php while ($row = mysqli_fetch_assoc($declined_result)) { ?>
                                    <tr>
                                        <td>
                                            <?php echo $row['student_id']; ?>
                                        </td>
                                        <td class="fw-bold">
                                            <?php echo $row['student_name']; ?>
                                        </td>
                                        <td>
                                            <?php echo $row['equipment_name']; ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-danger"><?php echo $row['status']; ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($row['reason'] ?? ''); ?></td>
                                    </tr>
                                <?php } ?>
                            <?php } ?>
                        </tbody>

                    </table>
                </div>
            </div>


            <div id="sec-raw-data" class="view-section">
                <h4 class="fw-bold mb-4 text-secondary">
                    <i class="bi bi-database-fill me-2"></i> Master Request Log (Raw Data)
                </h4>

                <form method="GET" action="admin-dashboard.php#sec-raw-data" class="mb-3">
                    <input type="hidden" name="view" value="raw">
                    <div class="input-group">
                        <input type="text" 
                               name="raw_search"
                               id="rawSearch"  
                               class="form-control"
                               placeholder="Search by Student Name, ID, or Item..."
                               value="<?php echo $_GET['raw_search'] ?? ''; ?>">
                    </div>
                </form>

                <div class="table-responsive">
                    <table class="table table-bordered table-striped align-middle">
                        <thead class="table-dark">
                            <tr>
                                <th>Student ID</th>
                                <th>Student Name</th>
                                <th>Equipment</th>
                                <th>Instructor</th>
                                <th>Room</th>
                                <th>Borrow Date</th>
                                <th>Return Date</th>
                                <th>Date Filed</th>
                            </tr>
                        </thead>
                        <tbody id="raw-data-body">
                            <?php if (mysqli_num_rows($raw_data_result) === 0) { ?>
                                <tr>
                                    <td colspan="8" class="text-center text-muted py-5">
                                        No historical records found.
                                    </td>
                                </tr>
                            <?php } else { ?>
                                <?php while ($row = mysqli_fetch_assoc($raw_data_result)) { ?>
                                    <tr>
                                        <td>
                                            <?php echo $row['student_id']; ?>
                                        </td>
                                        <td class="fw-bold">
                                            <?php echo htmlspecialchars($row['student_name']); ?>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($row['equipment_name']); ?>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($row['instructor']); ?>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($row['room']); ?>
                                        </td>
                                        <td>
                                            <?php echo date('M d, Y', strtotime($row['borrow_date'])); ?>
                                        </td>
                                        <td>
                                            <?php echo date('M d, Y', strtotime($row['return_date'])); ?>
                                        </td>
                                        <td>
                                            <small class="text-muted">
                                                <?php echo date('M d, Y g:i A', strtotime($row['request_date'])); ?>
                                            </small>
                                        </td>
                                    </tr>
                                <?php } ?>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>
    </div>

    <div class="modal fade" id="itemModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content shadow-lg border-0 rounded-4">

                <div class="modal-header text-white" style="background: var(--maroon);">
                    <h5 class="modal-title fw-bold">
                        <?php echo $edit_item ? "Edit Equipment" : "Add Equipment"; ?>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body p-4">
                    <form method="POST" enctype="multipart/form-data">

                        <?php if ($edit_item) { ?>
                            <input type="hidden" name="item_id" value="<?php echo $edit_item['item_id']; ?>">
                            <input type="hidden" name="old_image" value="<?php echo $edit_item['image_path']; ?>">
                        <?php } ?>

                        <div class="text-center mb-3">
                            <div id="dropZone" class="border rounded-3 p-3 text-center mb-3 position-relative"
                                style="cursor:pointer; background:#f8f9fa;">

                                <!-- REMOVE IMAGE BUTTON -->
                                <button type="button" id="removeImageBtn"
                                    class="btn btn-sm btn-danger position-absolute top-0 end-0 m-1 d-none">
                                    <i class="bi bi-x-lg"></i>
                                </button>

                                <img id="imagePreview"
                                    src="<?php echo $edit_item ? $edit_item['image_path'] : 'uploads/default.png'; ?>"
                                    class="item-img mb-2">

                                <p class="text-muted mb-0">
                                    Drag & drop image here<br>
                                    or click / paste (Ctrl + V)
                                </p>

                                <input type="file" name="item_image" id="itemImageInput" class="d-none"
                                    accept="image/*">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">Item Name</label>
                            <input type="text" name="item_name" class="form-control"
                                value="<?php echo $edit_item['item_name'] ?? ''; ?>"
                                oninput="if(this.value.length > 25) this.value = this.value.slice(0, 25);" required>
                        </div>

                        <div class="row">
                            <div class="col-6 mb-3">
                                <label class="form-label fw-bold">Quantity</label>
                                <input type="number" name="quantity" class="form-control" min="0" max="100"
                                    value="<?php echo $edit_item['quantity'] ?? 1; ?>"
                                    oninput="if(this.value > 100) this.value = this.value = 100;" required>
                            </div>

                            <div class="col-6 mb-3">
                                <label class="form-label fw-bold">Category</label>
                                <select name="category" class="form-select">
                                    <?php
                                    $categories = [
                                        "Electronics and Accessories",
                                        "Academic tools",
                                        "Sports and Physical Education Equipment",
                                        "Others"
                                    ];
                                    foreach ($categories as $cat) {
                                        $selected = ($edit_item && $edit_item['category'] == $cat) ? "selected" : "";
                                        echo "<option $selected>$cat</option>";
                                    }
                                    ?>
                                </select>
                            </div>

                            <button type="submit" name="<?php echo $edit_item ? 'update_item' : 'add_item'; ?>"
                                class="btn btn-success w-100 py-3 rounded-3 fw-bold">
                                Save Changes
                            </button>

                    </form>
                </div>
            </div>
        </div>
    </div>


    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <?php if ($edit_item) { ?>
        <script>
            window.onload = function () {
                new bootstrap.Modal(document.getElementById('itemModal')).show();
            };
        </script>
    <?php } ?>

    <script src="js/admin-dashboard.js"></script>

</body>

</html>