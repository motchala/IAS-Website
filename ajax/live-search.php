<?php
$conn = mysqli_connect("localhost", "root", "", "lending_db");
if (!$conn) exit("DB Error");

$search = $_GET['q'] ?? '';
$search = "%" . $search . "%";
$section = $_GET['section'] ?? 'waiting';

switch ($section) {
    case 'waiting':
        $sql = "SELECT * FROM tbl_requests 
                WHERE status='Waiting' AND (
                    student_id LIKE ? OR
                    student_name LIKE ? OR
                    equipment_name LIKE ?
                ) ORDER BY request_date DESC";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sss", $search, $search, $search);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            echo "<tr>
                    <td colspan='7' class='text-center text-muted py-5'>
                        <i class='bi bi-inbox fs-1 d-block mb-2'></i>
                        No matching results found.
                    </td>
                  </tr>";
            exit();
        }

        while ($row = $result->fetch_assoc()) {
            $isPast = strtotime($row['borrow_date']) < strtotime(date('Y-m-d'));
            echo "<tr>
                <td>{$row['student_id']}</td>
                <td class='fw-bold'>" . htmlspecialchars($row['student_name']) . "</td>
                <td>" . htmlspecialchars($row['equipment_name']) . "</td>
                <td class='" . ($isPast ? "text-danger fw-bold" : "") . "'>
                    " . date('M d, Y', strtotime($row['borrow_date'])) . "
                    " . ($isPast ? "<small class='d-block' style='font-size:0.7rem;'>(Date Passed)</small>" : "") . "
                </td>
                <td>" . date('M d, Y', strtotime($row['return_date'])) . "</td>
                <td>
                    <span class='badge bg-warning text-dark px-3 py-2'>{$row['status']}</span>
                </td>
                <td>
                    <a href='admin-dashboard.php?action=approve&id={$row['id']}' class='btn btn-success btn-sm btn-circle-sm'>
                        <i class='bi bi-check-lg'></i>
                    </a>
                    <a href='admin-dashboard.php?action=decline&id={$row['id']}' class='btn btn-danger btn-sm btn-circle-sm ms-1'>
                        <i class='bi bi-x-lg'></i>
                    </a>
                </td>
            </tr>";
        }
        break;

    case 'approved':
    case 'declined':
        $status = ($section === 'approved') ? 'Approved' : 'Declined';
        $sql = "SELECT * FROM tbl_requests 
                WHERE status=? AND (
                    student_id LIKE ? OR
                    student_name LIKE ? OR
                    equipment_name LIKE ?
                ) ORDER BY request_date DESC";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssss", $status, $search, $search, $search);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            echo "<tr><td colspan='4' class='text-center text-muted py-5'>No results found</td></tr>";
            exit();
        }

        while ($row = $result->fetch_assoc()) {
            echo "<tr>
                <td>{$row['student_id']}</td>
                <td class='fw-bold'>" . htmlspecialchars($row['student_name']) . "</td>
                <td>" . htmlspecialchars($row['equipment_name']) . "</td>
                <td><span class='badge " . ($status==='Approved'?'bg-success':'bg-danger') . "'>{$row['status']}</span></td>
            </tr>";
        }
        break;

    case 'inventory':
        // only return active (non-archived) items during live search
        $sql = "SELECT * FROM tbl_inventory 
                WHERE is_archived = 0
                  AND (item_name LIKE ? OR category LIKE ?)
                ORDER BY created_at DESC";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ss", $search, $search);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            echo "<tr><td colspan='6' class='text-center text-muted py-5'>Inventory empty</td></tr>";
            exit();
        }

        while ($item = $result->fetch_assoc()) {
            echo "<tr>
                <td><img src='{$item['image_path']}' class='item-img shadow-sm'></td>
                <td class='fw-bold text-maroon'>" . htmlspecialchars($item['item_name']) . "</td>
                <td>" . htmlspecialchars($item['category']) . "</td>
                <td><span class='badge bg-info text-dark'>{$item['quantity']} units</span></td>
                <td>" . ($item['quantity']>0? "<span class='badge bg-success'>Available</span>":"<span class='badge bg-danger'>No Stock</span>") . "</td>
                <td>
                    <a href='admin-dashboard.php?edit_item={$item['item_id']}#sec-inventory' class='btn btn-sm btn-outline-primary'>
                        <i class='bi bi-pencil'></i>
                    </a>
                    <a href='admin-dashboard.php?delete_item={$item['item_id']}' class='btn btn-sm btn-outline-danger' onclick='return confirm(\"Delete this item?\");'>
                        <i class='bi bi-trash'></i>
                    </a>
                </td>
            </tr>";
        }
        break;

    case 'raw':
        $sql = "SELECT student_id, student_name, equipment_name, instructor, room, borrow_date, return_date, request_date 
                FROM tbl_requests 
                WHERE student_id LIKE ? OR student_name LIKE ? OR equipment_name LIKE ? 
                ORDER BY request_date DESC";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sss", $search, $search, $search);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            echo "<tr><td colspan='8' class='text-center text-muted py-5'>No records found</td></tr>";
            exit();
        }

        while ($row = $result->fetch_assoc()) {
            echo "<tr>
                <td>{$row['student_id']}</td>
                <td class='fw-bold'>" . htmlspecialchars($row['student_name']) . "</td>
                <td>" . htmlspecialchars($row['equipment_name']) . "</td>
                <td>" . htmlspecialchars($row['instructor']) . "</td>
                <td>" . htmlspecialchars($row['room']) . "</td>
                <td>" . date('M d, Y', strtotime($row['borrow_date'])) . "</td>
                <td>" . date('M d, Y', strtotime($row['return_date'])) . "</td>
                <td><small class='text-muted'>" . date('M d, Y g:i A', strtotime($row['request_date'])) . "</small></td>
            </tr>";
        }
        break;

    default:
        echo "<tr><td colspan='7'>Invalid section</td></tr>";
}
