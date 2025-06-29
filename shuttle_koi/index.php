<?php
session_start();
require_once 'config/database.php';

// Error/success message handling
$login_error = $_GET['login_error'] ?? '';
$signup_error = $_GET['signup_error'] ?? '';
$signup_success = $_GET['success'] ?? '';
$show_login_modal = $login_error ? 'true' : 'false';
$show_signup_modal = $signup_error ? 'true' : 'false';

// Real-time stats
$students_served = 0;
$active_shuttles = 0;
$stops_count = 0;
$avg_rating = 0;

try {
    $database = new Database();
    $db = $database->getConnection();

    // Students Served
    $stmt = $db->query('SELECT COUNT(*) FROM Student');
    $students_served = (int)$stmt->fetchColumn();

    // Active Shuttles
    $stmt = $db->query("SELECT COUNT(*) FROM Shuttle WHERE Status = 'Active'");
    $active_shuttles = (int)$stmt->fetchColumn();

    // Stops
    $stmt = $db->query('SELECT COUNT(*) FROM Stop');
    $stops_count = (int)$stmt->fetchColumn();

    // Average Rating
    $stmt = $db->query('SELECT AVG(Rating) as avg_rating FROM Feedback');
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $avg_rating = $row && $row['avg_rating'] !== null ? round($row['avg_rating'], 1) : 0;
} catch (Exception $e) {
    // If DB error, keep stats as 0
}

// Check if user is already logged in
if (isset($_SESSION['user_id']) && isset($_SESSION['user_type'])) {
    $redirect_url = '';
    switch ($_SESSION['user_type']) {
        case 'student':
            $redirect_url = 'student/dashboard.php';
            break;
        case 'driver':
            $redirect_url = 'driver/dashboard.php';
            break;
        case 'admin':
            $redirect_url = 'admin/dashboard.php';
            break;
    }
    if ($redirect_url) {
        header("Location: $redirect_url");
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shuttle Koi - University Shuttle Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #2c3e50;
            --secondary-color: #3498db;
            --accent-color: #e74c3c;
            --light-color: #ecf0f1;
            --dark-color: #2c3e50;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }

        .hero-section {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 3rem;
            margin: 2rem 0;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
        }

        .feature-card {
            background: white;
            border-radius: 15px;
            padding: 2rem;
            margin: 1rem 0;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .feature-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
        }

        .feature-icon {
            font-size: 3rem;
            color: var(--secondary-color);
            margin-bottom: 1rem;
        }

        .btn-custom {
            background: linear-gradient(45deg, var(--secondary-color), var(--primary-color));
            border: none;
            border-radius: 25px;
            padding: 12px 30px;
            color: white;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(52, 152, 219, 0.4);
            color: white;
        }

        .modal-content {
            border-radius: 20px;
            border: none;
        }

        .form-control {
            border-radius: 10px;
            border: 2px solid #e9ecef;
            padding: 12px 15px;
        }

        .form-control:focus {
            border-color: var(--secondary-color);
            box-shadow: 0 0 0 0.2rem rgba(52, 152, 219, 0.25);
        }

        .navbar-brand {
            font-weight: bold;
            font-size: 1.5rem;
        }

        .stats-section {
            background: rgba(255, 255, 255, 0.9);
            border-radius: 20px;
            padding: 2rem;
            margin: 2rem 0;
        }

        .stat-item {
            text-align: center;
            padding: 1rem;
        }

        .stat-number {
            font-size: 2.5rem;
            font-weight: bold;
            color: var(--secondary-color);
        }

        .stat-label {
            color: var(--dark-color);
            font-weight: 500;
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark" style="background: rgba(44, 62, 80, 0.9); backdrop-filter: blur(10px);">
        <div class="container">
            <a class="navbar-brand" href="#">
                <i class="fas fa-shuttle-van me-2"></i>Shuttle Koi
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="#features">Features</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#about">About</a>
                    </li>
                    <li class="nav-item">
                        <button class="btn btn-custom ms-2" data-bs-toggle="modal" data-bs-target="#loginModal">
                            <i class="fas fa-sign-in-alt me-2"></i>Login
                        </button>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <div class="container">
        <div class="hero-section text-center text-white">
            <h1 class="display-4 fw-bold mb-4">
                <i class="fas fa-shuttle-van me-3"></i>
                Shuttle Koi
            </h1>
            <p class="lead mb-4">Smart University Shuttle Management System</p>
            <p class="mb-4">Real-time tracking, smart notifications, and efficient route management for a seamless shuttle experience.</p>
            <div class="d-flex justify-content-center gap-3 flex-wrap">
                <button class="btn btn-custom btn-lg" data-bs-toggle="modal" data-bs-target="#signupModal">
                    <i class="fas fa-user-plus me-2"></i>Get Started
                </button>
                <button class="btn btn-outline-light btn-lg" data-bs-toggle="modal" data-bs-target="#loginModal">
                    <i class="fas fa-sign-in-alt me-2"></i>Login
                </button>
            </div>
        </div>

        <!-- Stats Section -->
        <div class="stats-section">
            <div class="row">
                <div class="col-md-3 col-6">
                    <div class="stat-item">
                        <div class="stat-number"><?php echo $students_served; ?></div>
                        <div class="stat-label">Students Served</div>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="stat-item">
                        <div class="stat-number"><?php echo $active_shuttles; ?></div>
                        <div class="stat-label">Active Shuttles</div>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="stat-item">
                        <div class="stat-number"><?php echo $stops_count; ?></div>
                        <div class="stat-label">Stops</div>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="stat-item">
                        <div class="stat-number"><?php echo $avg_rating; ?></div>
                        <div class="stat-label">Rating</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Features Section -->
        <div id="features" class="row mt-5">
            <div class="col-12 text-center mb-5">
                <h2 class="fw-bold text-white">Key Features</h2>
            </div>
            
            <div class="col-lg-4 col-md-6">
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-map-marker-alt"></i>
                    </div>
                    <h4>Real-Time Tracking</h4>
                    <p>Track your shuttle's exact location in real-time using Google Maps integration.</p>
                </div>
            </div>
            
            <div class="col-lg-4 col-md-6">
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-bell"></i>
                    </div>
                    <h4>Smart Notifications</h4>
                    <p>Get instant alerts when your shuttle is nearby or when there are queue updates.</p>
                </div>
            </div>
            
            <div class="col-lg-4 col-md-6">
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-star"></i>
                    </div>
                    <h4>Rating & Feedback</h4>
                    <p>Rate your ride experience and provide feedback to help improve the service.</p>
                </div>
            </div>
            
            <div class="col-lg-4 col-md-6">
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <h4>Queue Management</h4>
                    <p>Efficient queue management with real-time count and smart alerts for drivers.</p>
                </div>
            </div>
            
            <div class="col-lg-4 col-md-6">
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-route"></i>
                    </div>
                    <h4>Route Optimization</h4>
                    <p>AI-powered route suggestions based on traffic data and current demand.</p>
                </div>
            </div>
            
            <div class="col-lg-4 col-md-6">
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <h4>Analytics & Reports</h4>
                    <p>Comprehensive reports and analytics for administrators to monitor performance.</p>
                </div>
            </div>
        </div>

        <!-- About Section -->
        <div id="about" class="hero-section text-white mt-5">
            <div class="row">
                <div class="col-lg-6">
                    <h3 class="fw-bold mb-4">About Shuttle Koi</h3>
                    <p class="mb-4">Shuttle Koi is a comprehensive university shuttle management system designed to enhance the commuting experience for students, drivers, and administrators.</p>
                    <p class="mb-4">Our system leverages cutting-edge technology to provide real-time tracking, smart notifications, and efficient route management, ensuring everyone gets to their destination on time and with minimal hassle.</p>
                </div>
                <div class="col-lg-6">
                    <h5 class="fw-bold mb-3">Technology Stack</h5>
                    <ul class="list-unstyled">
                        <li><i class="fas fa-check text-success me-2"></i>PHP & MySQL Backend</li>
                        <li><i class="fas fa-check text-success me-2"></i>HTML5, CSS3, JavaScript Frontend</li>
                        <li><i class="fas fa-check text-success me-2"></i>Google Maps API Integration</li>
                        <li><i class="fas fa-check text-success me-2"></i>Real-time Notifications</li>
                        <li><i class="fas fa-check text-success me-2"></i>Responsive Design</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <!-- Login Modal -->
    <div class="modal fade" id="loginModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header border-0">
                    <h5 class="modal-title fw-bold">Login to Shuttle Koi</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <?php if ($login_error): ?>
                    <div class="alert alert-danger">
                        <?php
                        switch ($login_error) {
                            case 'missing_fields':
                                echo 'Please fill in all fields.';
                                break;
                            case 'invalid_user_type':
                                echo 'Invalid user type selected.';
                                break;
                            case 'invalid_password':
                            case 'user_not_found':
                                echo 'Invalid email or password, please try again.';
                                break;
                            case 'database_error':
                                echo 'A database error occurred. Please try again later.';
                                break;
                            default:
                                echo 'Login failed. Please try again.';
                        }
                        ?>
                    </div>
                    <?php endif; ?>
                    <form id="loginForm" action="auth/login.php" method="POST">
                        <div class="mb-3">
                            <label class="form-label">User Type</label>
                            <select class="form-select" name="user_type" required>
                                <option value="">Select User Type</option>
                                <option value="student">Student</option>
                                <option value="driver">Driver</option>
                                <option value="admin">Admin</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" name="email" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Password</label>
                            <input type="password" class="form-control" name="password" required>
                        </div>
                        <button type="submit" class="btn btn-custom w-100">Login</button>
                    </form>
                    <div class="text-center mt-3">
                        <p>Don't have an account? <a href="#" data-bs-toggle="modal" data-bs-target="#signupModal" data-bs-dismiss="modal">Sign up</a></p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Signup Modal -->
    <div class="modal fade" id="signupModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header border-0">
                    <h5 class="modal-title fw-bold">Create Account</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <?php if ($signup_error): ?>
                    <div class="alert alert-danger">
                        <?php
                        switch ($signup_error) {
                            case 'missing_fields':
                                echo 'Please fill in all fields.';
                                break;
                            case 'password_mismatch':
                                echo 'Passwords do not match!';
                                break;
                            case 'invalid_email':
                                echo 'Invalid email address.';
                                break;
                            case 'password_too_short':
                                echo 'Password must be at least 6 characters long!';
                                break;
                            case 'email_exists':
                                echo 'This email is already registered.';
                                break;
                            case 'registration_failed':
                                echo 'Registration failed. Please try again.';
                                break;
                            case 'database_error':
                                echo 'A database error occurred. Please try again later.';
                                break;
                            default:
                                echo 'Signup failed. Please try again.';
                        }
                        ?>
                    </div>
                    <?php endif; ?>
                    <form id="signupForm" action="auth/signup.php" method="POST">
                        <div class="mb-3">
                            <label class="form-label">User Type</label>
                            <select class="form-select" name="user_type" required>
                                <option value="">Select User Type</option>
                                <option value="student">Student</option>
                                <option value="driver">Driver</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Full Name</label>
                            <input type="text" class="form-control" name="name" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" name="email" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Phone Number</label>
                            <input type="tel" class="form-control" name="phone">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Password</label>
                            <input type="password" class="form-control" name="password" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Confirm Password</label>
                            <input type="password" class="form-control" name="confirm_password" required>
                        </div>
                        <button type="submit" class="btn btn-custom w-100">Create Account</button>
                    </form>
                    <div class="text-center mt-3">
                        <p>Already have an account? <a href="#" data-bs-toggle="modal" data-bs-target="#loginModal" data-bs-dismiss="modal">Login</a></p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="text-center text-white py-4 mt-5" style="background: rgba(44, 62, 80, 0.9);">
        <div class="container">
            <p>&copy; 2024 Shuttle Koi. All rights reserved.</p>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Form validation
        document.getElementById('signupForm').addEventListener('submit', function(e) {
            const password = this.querySelector('input[name="password"]').value;
            const confirmPassword = this.querySelector('input[name="confirm_password"]').value;
            
            if (password !== confirmPassword) {
                e.preventDefault();
                alert('Passwords do not match!');
                return false;
            }
            
            // Additional validation
            if (password.length < 6) {
                e.preventDefault();
                alert('Password must be at least 6 characters long!');
                return false;
            }
        });

        // Smooth scrolling for navigation links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                document.querySelector(this.getAttribute('href')).scrollIntoView({
                    behavior: 'smooth'
                });
            });
        });

        // Show modals if there are errors
        document.addEventListener('DOMContentLoaded', function() {
            var showLogin = <?php echo $show_login_modal; ?>;
            var showSignup = <?php echo $show_signup_modal; ?>;
            if (showLogin) {
                var loginModal = new bootstrap.Modal(document.getElementById('loginModal'));
                loginModal.show();
            }
            if (showSignup) {
                var signupModal = new bootstrap.Modal(document.getElementById('signupModal'));
                signupModal.show();
            }
        });
    </script>
</body>
</html> 