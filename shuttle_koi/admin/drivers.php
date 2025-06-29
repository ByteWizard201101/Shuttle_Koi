<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header('Location: ../index.php');
    exit();
}

$database = new Database();
$db = $database->getConnection();

// Pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 15;
$offset = ($page - 1) * $per_page;

// Search
$search = $_GET['search'] ?? '';
$where = '';
$params = [];
if ($search) {
    $where = "WHERE Name LIKE :search OR Email LIKE :search OR Phone_Number LIKE :search";
    $params[':search'] = "%$search%";
}

// Get total count
$count_query = "SELECT COUNT(*) FROM Driver $where";
$count_stmt = $db->prepare($count_query);
$count_stmt->execute($params);
$total = $count_stmt->fetchColumn();
$total_pages = ceil($total / $per_page);

// Get drivers
$query = "SELECT * FROM Driver $where ORDER BY Name LIMIT :offset, :per_page";
$stmt = $db->prepare($query);
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->bindValue(':per_page', $per_page, PDO::PARAM_INT);
$stmt->execute();
$drivers = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Drivers - Shuttle Koi</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container">
        <a class="navbar-brand" href="dashboard.php"><i class="fas fa-arrow-left me-2"></i>Back to Dashboard</a>
        <span class="navbar-text ms-auto">Driver Management</span>
    </div>
</nav>
<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3><i class="fas fa-user-tie me-2"></i>Drivers</h3>
        <form class="d-flex" method="get">
            <input class="form-control me-2" type="search" name="search" placeholder="Search by name, email, phone" value="<?php echo htmlspecialchars($search); ?>">
            <button class="btn btn-outline-success" type="submit">Search</button>
        </form>
    </div>
    <div class="card">
        <div class="card-body p-0">
            <table class="table table-bordered align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>License Number</th>
                        <th style="width: 160px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($drivers as $driver): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($driver['Name']); ?></td>
                        <td><?php echo htmlspecialchars($driver['Email']); ?></td>
                        <td><?php echo htmlspecialchars($driver['Phone_Number']); ?></td>
                        <td><?php echo htmlspecialchars($driver['License_Number']); ?></td>
                        <td>
                            <button class="btn btn-sm btn-primary me-2" data-bs-toggle="modal" data-bs-target="#editDriverModal<?php echo $driver['D_ID']; ?>">
                                <i class="fas fa-edit"></i> Edit
                            </button>
                            <button class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#deleteDriverModal<?php echo $driver['D_ID']; ?>">
                                <i class="fas fa-trash"></i> Delete
                            </button>
                        </td>
                    </tr>
                    <!-- Edit Driver Modal -->
                    <div class="modal fade" id="editDriverModal<?php echo $driver['D_ID']; ?>" tabindex="-1">
                        <div class="modal-dialog">
                            <div class="modal-content">
                                <form action="edit_driver.php" method="POST">
                                    <div class="modal-header">
                                        <h5 class="modal-title">Edit Driver</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body">
                                        <input type="hidden" name="driver_id" value="<?php echo $driver['D_ID']; ?>">
                                        <div class="mb-3">
                                            <label class="form-label">Name</label>
                                            <input type="text" class="form-control" name="name" value="<?php echo htmlspecialchars($driver['Name']); ?>" required>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Email</label>
                                            <input type="email" class="form-control" name="email" value="<?php echo htmlspecialchars($driver['Email']); ?>" required>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Phone</label>
                                            <input type="text" class="form-control" name="phone" value="<?php echo htmlspecialchars($driver['Phone_Number']); ?>">
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">License Number</label>
                                            <input type="text" class="form-control" name="license_number" value="<?php echo htmlspecialchars($driver['License_Number']); ?>">
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                        <button type="submit" class="btn btn-primary">Save Changes</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    <!-- Delete Driver Modal -->
                    <div class="modal fade" id="deleteDriverModal<?php echo $driver['D_ID']; ?>" tabindex="-1">
                        <div class="modal-dialog">
                            <div class="modal-content">
                                <form action="delete_driver.php" method="POST">
                                    <div class="modal-header">
                                        <h5 class="modal-title">Delete Driver</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body">
                                        <input type="hidden" name="driver_id" value="<?php echo $driver['D_ID']; ?>">
                                        <p>Are you sure you want to delete <strong><?php echo htmlspecialchars($driver['Name']); ?></strong>?</p>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                        <button type="submit" class="btn btn-danger">Delete</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <!-- Pagination -->
    <nav class="mt-3">
        <ul class="pagination justify-content-center">
            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                <li class="page-item <?php if ($i == $page) echo 'active'; ?>">
                    <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>"><?php echo $i; ?></a>
                </li>
            <?php endfor; ?>
        </ul>
    </nav>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 