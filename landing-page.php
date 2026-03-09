<?php
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '', 
    'secure' => false,     // Set to true if you are using HTTPS
    'httponly' => true,    // Prevents JS from accessing the cookie
    'samesite' => 'Lax'    // The fix for "Cookie without SameSite Attribute" (Low Alert)
]);

ini_set('session.cookie_httponly', 1); // The fix for "Cookie No HttpOnly Flag" (Low Alert)
session_start();

// ----------- CSRF TOKEN -----------
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// ----------- CONTENT SECURITY POLICY -----------
// A fresh nonce is generated on every request and embedded into every
// <script> tag. Only scripts carrying this nonce (or from listed trusted
// origins) are allowed to execute.
$csp_nonce = base64_encode(random_bytes(16));

header("Content-Security-Policy: " . implode('; ', [
    // Only this page's own origin may frame it
    "default-src 'self'",

    // Scripts: own origin + the per-request nonce for the inline INIT block.
    // No 'unsafe-hashes' needed — all event handlers are in landing-page.js.
    "script-src 'self' 'nonce-{$csp_nonce}'",

    // Styles: own origin + Google Fonts CSS + Font Awesome CDN
    "style-src 'self' https://fonts.googleapis.com https://cdnjs.cloudflare.com",

    // Fonts: Google Fonts files + Font Awesome font files on cdnjs
    "font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com",

    // Images: own origin + data URIs (used by some browser extensions; safe to allow)
    "img-src 'self' data:",

    // No plugins (Flash, Java applets, etc.)
    "object-src 'none'",

    // Disallow embedding this page in iframes on other origins (clickjacking)
    "frame-ancestors 'self'",

    // All form POSTs must go to same origin only
    "form-action 'self'",

    // Force HTTPS for all resource loads (no mixed content)
    "upgrade-insecure-requests",
]));

// X-Frame-Options is the legacy equivalent of CSP frame-ancestors.
// Sent alongside CSP so that older browsers (IE, legacy Edge) that
// don't understand CSP are also protected against clickjacking.
header("X-Frame-Options: SAMEORIGIN");

function verifyCsrfToken()
{
    if (
        empty($_POST['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {
        http_response_code(403);
        exit('Forbidden: Invalid CSRF token.');
    }
}

function validateStudentIDYear($student_id)
{
    $year = intval(substr($student_id, 0, 4));
    return $year >= 2000 && $year <= 2030;
}

if (isset($_SESSION['user_id'])) {
    header("Location: user-dashboard.php");
    exit();
}
if (isset($_SESSION['admin'])) {
    header("Location: admin-dashboard.php");
    exit();
}

$conn = mysqli_connect("localhost", "root", "", "lending_db");
$login_error = $register_error = $register_success = "";
$login_email_val = $reg_fullname_val = $reg_studentid_val = $reg_email_val = "";

// ----------- LOGIN -----------
if (isset($_POST['login'])) {
    verifyCsrfToken();
    $email    = trim($_POST['email']);
    $password = $_POST['password'];
    $login_email_val = $email;

    if ($email === 'main@admin.edu' && $password === 'admin123') {
        $_SESSION['admin'] = true;
        $_SESSION['login_time'] = time();
        header("Location: admin-dashboard.php");
        exit();
    }

    $stmt = $conn->prepare("SELECT student_id, fullname, password FROM tbl_users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        if (password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['student_id'];
            $_SESSION['fullname'] = $user['fullname'];
            $_SESSION['login_time'] = time();
            header("Location: user-dashboard.php");
            exit();
        } else {
            $login_error = "Incorrect password.";
        }
    } else {
        $login_error = "No account found with that email.";
    }
}

// ----------- REGISTRATION -----------
if (isset($_POST['register'])) {
    verifyCsrfToken();
    $fullname         = trim($_POST['fullname']);
    $student_id       = trim($_POST['student_id']);
    $email            = trim($_POST['email']);
    $password         = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];

    $reg_fullname_val  = $fullname;
    $reg_studentid_val = $student_id;
    $reg_email_val     = $email;

    if (!$fullname || !$student_id || !$email || !$password || !$confirm_password) {
        $register_error = "Please fill in all fields.";
    } elseif ($password !== $confirm_password) {
        $register_error = "Passwords do not match.";
    } elseif (strlen($student_id) != 15) {
        $register_error = "Student ID must be exactly 15 characters.";
    } elseif (!preg_match('/^2[0-9]{3}-[0-9]{5}-BN-[0-9]$/', $student_id)) {
        $register_error = "Invalid Student ID format. Use: YYYY-XXXXX-BN-X";
    } elseif (!validateStudentIDYear($student_id)) {
        $register_error = "Year must be between 2000 and 2030.";
    } elseif (strlen($password) < 4) {
        $register_error = "Password must be at least 4 characters.";
    } elseif (strlen($fullname) < 5 || strlen($fullname) > 70) {
        $register_error = "Full Name must be between 5 and 70 characters.";
    } elseif (strlen($email) < 15 || strlen($email) > 254) {
        $register_error = "Email must be between 15 and 254 characters.";
    } else {
        $stmt = $conn->prepare("SELECT * FROM tbl_users WHERE email = ? OR student_id = ?");
        $stmt->bind_param("ss", $email, $student_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $register_error = "Email or Student ID already exists.";
        } else {
            $hashed = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $conn->prepare("INSERT INTO tbl_users (fullname, student_id, email, password) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("ssss", $fullname, $student_id, $email, $hashed);
            if ($stmt->execute()) {
                $register_success = "Account created! Redirecting to sign in…";
                $reg_fullname_val = $reg_studentid_val = $reg_email_val = "";
            } else {
                $register_error = "Error: " . $stmt->error;
            }
        }
    }
}

$open_tab = "login";
if (!empty($register_error) || !empty($register_success)) $open_tab = "register";
$auto_open_modal = (!empty($login_error) || !empty($register_error) || !empty($register_success));
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>PUPSYNC — Student Equipment Lending</title>
    <!-- Performance: preconnect to font origins -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <!-- Preload first hero image for faster LCP -->
    <link rel="preload" as="image" href="images/7-hero-page.jpg">
    <!-- Fonts with display=swap to avoid FOIT -->
    <!-- SRI cannot be applied to Google Fonts: its CSS response varies per
         browser/region so no single hash can be pre-computed. It is trusted
         via the CSP style-src whitelist instead. -->
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,600;0,700;1,300;1,400;1,600&family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- SRI hash locks this exact versioned file on cdnjs. If the CDN ever
         serves a tampered copy the browser will refuse to load it. -->
    <link rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css"
        integrity="sha384-Gn5384xqQ1aoWXA+058RXPxPg6fy4IWvTNh0E263XmFcJlSAwiGgFAW/dAiS6JXm"
        crossorigin="anonymous">

    <link rel="stylesheet" href="css/landing-page.css">
</head>

<body>

    <div id="phoneFrameWrap">

        <!-- ================================================================
     HERO SECTION
================================================================ -->
        <section class="hero" id="hero">

            <!-- ---- CAROUSEL SLIDES ---- -->
            <div class="carousel-track" id="carouselTrack">
                <div class="carousel-slide"></div><!-- 1 -->
                <div class="carousel-slide"></div><!-- 2 -->
                <div class="carousel-slide"></div><!-- 3 -->
                <div class="carousel-slide"></div><!-- 4 -->
                <div class="carousel-slide"></div><!-- 5 -->
                <div class="carousel-slide"></div><!-- 6 -->
                <div class="carousel-slide active"></div><!-- 7 → starts here -->
            </div>

            <!-- Progress bar -->
            <div class="carousel-progress" id="carouselProgress"></div>

            <!-- Atmosphere -->
            <div class="hero-grad"></div>
            <div class="hero-grain"></div>
            <div class="hero-grid"></div>
            <div class="blob-tl"></div>
            <div class="blob-br"></div>

            <!-- Prev / Next arrows -->
            <button class="carousel-arrow prev" id="carouselPrev" aria-label="Previous image">
                <i class="fa-solid fa-chevron-left"></i>
            </button>
            <button class="carousel-arrow next" id="carouselNext" aria-label="Next image">
                <i class="fa-solid fa-chevron-right"></i>
            </button>

            <!-- Dot indicators -->
            <div class="carousel-dots" id="carouselDots"></div>

            <!-- Slide counter e.g. "7 / 7" -->
            <div class="carousel-counter" id="carouselCounter"></div>

            <!-- Top bar -->
            <div class="brand-mark">
                <div class="brand-mark-icon"><i class="fa-solid fa-boxes-stacked"></i></div>
                <div class="brand-mark-text">
                    <span class="name">
                        <span class="brand-pup">PUP</span><span class="brand-sync">SYNC</span>
                    </span>
                    <span class="sub">Equipment Lending</span>
                </div>
            </div>

            <a class="school-link" href="https://www.pup.edu.ph/binan/" target="_blank" rel="noopener">
                <i class="fa-solid fa-school"></i> PUP Biñan
            </a>

            <!-- Center content — everything except the decorative scroll hint
                 lives here so the layout is driven by normal document flow,
                 never by absolute positioning that can collide on short screens -->
            <div class="hero-content">
                <div class="hero-pill">
                    <span class="dot"></span>
                    Student Resource Portal &nbsp;·&nbsp; PUP Biñan
                </div>
                <h1 class="hero-h1">
                    <em>Borrow smart,</em><br>
                    <span class="line-accent">return proud.</span>
                </h1>
                <p class="hero-sub">
                    A secure, student-built platform that puts essential school equipment right at your fingertips — tracked, trusted, and always ready.
                </p>

                <!-- Buttons come first -->
                <div class="hero-cta-group">
                    <button class="btn-primary" id="heroLoginBtn" aria-label="Sign in to your account">
                        <span>Access Portal</span>
                        <span class="btn-icon"><i class="fa-solid fa-arrow-right"></i></span>
                    </button>
                    <button class="btn-ghost" id="heroRegisterBtn" aria-label="Create a new account">
                        <i class="fa-solid fa-user-plus"></i>
                        New here? Join free
                    </button>
                </div>

            </div>

        </section><!-- /hero -->


        <!-- ================================================================
     FOOTER
================================================================ -->
        <footer class="site-footer" id="site-footer">
            <div class="footer-accent-line"></div>
            <div class="footer-bg-blob"></div>

            <div class="footer-inner">

                <div class="footer-top">

                    <!-- Brand column -->
                    <div class="footer-brand-col">
                        <div class="footer-logo">
                            <div class="footer-logo-icon"><i class="fa-solid fa-boxes-stacked"></i></div>
                            <div class="footer-logo-text">
                                <span class="name">
                                    <span class="brand-pup">PUP</span><span class="brand-sync">SYNC</span>
                                </span>
                                <span class="tagline">A Centralized Resource Hub</span>
                            </div>
                        </div>
                        <p class="footer-brand-desc">
                            A student-built platform designed for the responsible borrowing and tracking of school equipment at PUP Biñan Campus — free, secure, and always available.
                        </p>
                        <div class="footer-socials">
                            <a class="social-btn" href="#" aria-label="Facebook"><i class="fa-brands fa-facebook-f"></i></a>
                            <a class="social-btn" href="#" aria-label="Twitter / X"><i class="fa-brands fa-x-twitter"></i></a>
                            <a class="social-btn" href="#" aria-label="Instagram"><i class="fa-brands fa-instagram"></i></a>
                            <a class="social-btn" href="mailto:pupsync@student.pup.edu.ph" aria-label="Email"><i class="fa-solid fa-envelope"></i></a>
                        </div>
                    </div>

                    <!-- Portal column -->
                    <div>
                        <p class="footer-col-title">Portal</p>
                        <ul class="footer-links">
                            <li><a href="#" data-modal="login"><i class="fa-solid fa-chevron-right"></i> Sign In</a></li>
                            <li><a href="#" data-modal="register"><i class="fa-solid fa-chevron-right"></i> Create Account</a></li>
                            <li><a href="user-dashboard.php"><i class="fa-solid fa-chevron-right"></i> My Dashboard</a></li>
                            <li><a href="user-dashboard.php"><i class="fa-solid fa-chevron-right"></i> Borrow Equipment</a></li>
                            <li><a href="user-dashboard.php"><i class="fa-solid fa-chevron-right"></i> My Requests</a></li>
                        </ul>
                    </div>

                    <!-- Info column -->
                    <div>
                        <p class="footer-col-title">Information</p>
                        <ul class="footer-links">
                            <li><a href="#"><i class="fa-solid fa-chevron-right"></i> How It Works</a></li>
                            <li><a href="#"><i class="fa-solid fa-chevron-right"></i> Equipment List</a></li>
                            <li><a href="#"><i class="fa-solid fa-chevron-right"></i> Lending Policy</a></li>
                            <li><a href="#"><i class="fa-solid fa-chevron-right"></i> Return Guidelines</a></li>
                            <li><a href="#"><i class="fa-solid fa-chevron-right"></i> FAQs</a></li>
                        </ul>
                    </div>

                    <!-- Contact column -->
                    <div>
                        <p class="footer-col-title">Contact</p>

                        <div class="footer-status">
                            <span class="status-dot"></span>
                            System Online
                        </div>

                        <div class="footer-info-item">
                            <i class="fa-solid fa-location-dot"></i>
                            <span>PUP Biñan Campus, Biñan, Laguna 4024, Philippines</span>
                        </div>
                        <div class="footer-info-item">
                            <i class="fa-solid fa-envelope"></i>
                            <span><a href="mailto:pupsync@student.pup.edu.ph">pupsync@student.pup.edu.ph</a></span>
                        </div>
                        <div class="footer-info-item">
                            <i class="fa-solid fa-globe"></i>
                            <span><a href="https://www.pup.edu.ph/binan/" target="_blank" rel="noopener">pup.edu.ph/binan</a></span>
                        </div>
                        <div class="footer-info-item">
                            <i class="fa-solid fa-clock"></i>
                            <span>Mon – Fri &nbsp;·&nbsp; 7:00 AM – 5:00 PM</span>
                        </div>
                    </div>

                </div><!-- /footer-top -->

                <div class="footer-rule"></div>

                <div class="footer-bottom">
                    <p class="footer-copy">
                        &copy; 2026 <strong>PUPSYNC</strong> — For Students, By Students.<br>
                        Part of <a href="https://www.pup.edu.ph/binan/" target="_blank" rel="noopener">Polytechnic University of the Philippines — Biñan Campus</a>.
                    </p>
                    <div class="footer-badges">
                        <span class="footer-badge"><i class="fa-solid fa-shield-halved"></i> Secure Auth</span>
                        <span class="footer-badge"><i class="fa-solid fa-lock"></i> Encrypted</span>
                        <button class="footer-badge mobile-toggle-btn" id="mobileToggleBtn" title="Switch to mobile preview layout" aria-pressed="false">
                            <i class="fa-solid fa-mobile-screen-button" id="mobileToggleIcon"></i>
                            <span id="mobileToggleLabel">Mobile Ready</span>
                        </button>
                    </div>
                </div>

                <!-- ── MOBILE COMPRESSED FOOTER ── shown only in .mobile-preview -->
                <div class="footer-mobile-bar">

                    <!-- Brand + status row -->
                    <div class="fmb-brand-row">
                        <div class="fmb-logo">
                            <div class="footer-logo-icon"><i class="fa-solid fa-boxes-stacked"></i></div>
                            <div class="footer-logo-text">
                                <span class="name"><span class="brand-pup">PUP</span><span class="brand-sync">SYNC</span></span>
                                <span class="tagline">Equipment Lending System</span>
                            </div>
                        </div>
                        <div class="footer-status">
                            <span class="status-dot"></span>
                            System Online
                        </div>
                    </div>

                    <!-- Link grid: 2 columns side by side -->
                    <div class="fmb-links-grid">
                        <div class="fmb-col">
                            <p class="footer-col-title">Portal</p>
                            <ul class="footer-links">
                                <li><a href="user-dashboard.php"><i class="fa-solid fa-chevron-right"></i> My Dashboard</a></li>
                                <li><a href="user-dashboard.php"><i class="fa-solid fa-chevron-right"></i> Borrow Equipment</a></li>
                                <li><a href="user-dashboard.php"><i class="fa-solid fa-chevron-right"></i> My Requests</a></li>
                            </ul>
                        </div>
                        <div class="fmb-col">
                            <p class="footer-col-title">Information</p>
                            <ul class="footer-links">
                                <li><a href="#"><i class="fa-solid fa-chevron-right"></i> How It Works</a></li>
                                <li><a href="#"><i class="fa-solid fa-chevron-right"></i> Equipment List</a></li>
                                <li><a href="#"><i class="fa-solid fa-chevron-right"></i> Lending Policy</a></li>
                                <li><a href="#"><i class="fa-solid fa-chevron-right"></i> FAQs</a></li>
                            </ul>
                        </div>
                    </div>

                    <!-- Contact strip -->
                    <div class="fmb-contact-strip">
                        <div class="footer-info-item">
                            <i class="fa-solid fa-location-dot"></i>
                            <span>PUP Biñan Campus, Biñan, Laguna 4024</span>
                        </div>
                        <div class="footer-info-item">
                            <i class="fa-solid fa-envelope"></i>
                            <span><a href="mailto:pupsync@student.pup.edu.ph">pupsync@student.pup.edu.ph</a></span>
                        </div>
                        <div class="footer-info-item">
                            <i class="fa-solid fa-clock"></i>
                            <span>Mon – Fri &nbsp;·&nbsp; 7:00 AM – 5:00 PM</span>
                        </div>
                    </div>

                    <!-- Socials -->
                    <div class="footer-socials fmb-socials">
                        <a class="social-btn" href="#" aria-label="Facebook"><i class="fa-brands fa-facebook-f"></i></a>
                        <a class="social-btn" href="#" aria-label="Twitter / X"><i class="fa-brands fa-x-twitter"></i></a>
                        <a class="social-btn" href="#" aria-label="Instagram"><i class="fa-brands fa-instagram"></i></a>
                        <a class="social-btn" href="mailto:equiplend@student.pup.edu.ph" aria-label="Email"><i class="fa-solid fa-envelope"></i></a>
                    </div>

                    <!-- Bottom rule + copyright + desktop toggle -->
                    <div class="fmb-bottom">
                        <p class="footer-copy">
                            &copy; 2026 <strong>PUPSYNC</strong> — For Students, By Students.<br>
                            <a href="https://www.pup.edu.ph/binan/" target="_blank" rel="noopener">PUP Biñan Campus</a>
                        </p>
                        <button class="fmb-desktop-btn" id="fmbDesktopBtn" aria-label="Switch to desktop layout">
                            <i class="fa-solid fa-desktop"></i> Desktop View
                        </button>
                    </div>

                </div><!-- /footer-mobile-bar -->

            </div><!-- /footer-inner -->
        </footer>


        <!-- /phoneFrameWrap -->
    </div>

    <!-- ================================================================
     AUTH MODAL — lives OUTSIDE phoneFrameWrap so position:fixed
     is always relative to the actual viewport, not the frame element
================================================================ -->
    <div class="modal-overlay" id="authModal" role="dialog" aria-modal="true" aria-label="Sign in or Register">
        <div class="modal-backdrop" id="modalBackdrop"></div>
        <div class="modal-card" id="modalCard">

            <div class="modal-handle" id="modalHandle" role="button" tabindex="0"
                aria-label="Minimize or restore auth panel">
                <div class="modal-handle-bar">
                    <div class="modal-handle-pill"></div>
                    <span class="modal-handle-label">Student Portal</span>
                    <span class="modal-minimized-hint">Tap to expand</span>
                </div>
                <div class="modal-handle-actions" id="modalHandleActions">
                    <div class="modal-action-btn" id="minimizeBtn"
                        title="Minimize" aria-label="Minimize panel" role="button" tabindex="0">
                        <i class="fa-solid fa-minus"></i>
                    </div>
                    <div class="modal-action-btn" id="closeBtn"
                        title="Close" aria-label="Close panel" role="button" tabindex="0">
                        <i class="fa-solid fa-xmark"></i>
                    </div>
                </div>
            </div>

            <div class="modal-body" id="modalBody">

                <div class="auth-tabs" role="tablist">
                    <button class="auth-tab-btn" id="tab-login" role="tab" aria-selected="true">
                        <i class="fa-solid fa-arrow-right-to-bracket"></i> Sign In
                    </button>
                    <button class="auth-tab-btn" id="tab-register" role="tab" aria-selected="false">
                        <i class="fa-solid fa-user-plus"></i> Register
                    </button>
                </div>

                <!-- LOGIN PANE -->
                <div class="auth-pane" id="pane-login" role="tabpanel">
                    <p class="pane-title">Welcome back</p>
                    <p class="pane-subtitle">Access PUP Biñan's Resource Hub</p>
                    <?php if ($login_error): ?>
                        <div class="auth-alert error">
                            <i class="fa-solid fa-circle-exclamation"></i>
                            <?= htmlspecialchars($login_error) ?>
                        </div>
                    <?php endif; ?>
                    <form method="POST" action="">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                        <div class="form-group">
                            <label for="login-email">Email</label>
                            <div class="input-wrap">
                                <input class="form-field" type="email" id="login-email" name="email"
                                    placeholder="youremail@student.edu.ph"
                                    value="<?= htmlspecialchars($login_email_val) ?>"
                                    autocomplete="email" required>
                                <i class="fa-solid fa-envelope input-icon-left"></i>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="login-pass">Password</label>
                            <div class="input-wrap">
                                <input class="form-field" type="password" id="login-pass" name="password"
                                    placeholder="Enter your password"
                                    autocomplete="current-password" required>
                                <i class="fa-solid fa-lock input-icon-left"></i>
                                <button type="button" class="eye-toggle" data-target="login-pass" tabindex="-1" aria-label="Show password">
                                    <i class="fa-regular fa-eye-slash"></i>
                                </button>
                            </div>
                        </div>
                        <button type="submit" name="login" class="btn-auth">
                            <i class="fa-solid fa-arrow-right-to-bracket"></i>
                            Sign In
                        </button>
                    </form>
                    <div class="modal-footer-link">
                        <span>No account?</span>
                        <button id="goToRegister">Register here</button>
                    </div>
                </div>

                <!-- REGISTER PANE -->
                <div class="auth-pane" id="pane-register" role="tabpanel">
                    <p class="pane-title">New account</p>
                    <p class="pane-subtitle">Join PUP Biñan's centralized resource system.</p>
                    <?php if ($register_error): ?>
                        <div class="auth-alert error">
                            <i class="fa-solid fa-circle-exclamation"></i>
                            <?= htmlspecialchars($register_error) ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($register_success): ?>
                        <div class="auth-alert success">
                            <i class="fa-solid fa-circle-check"></i>
                            <?= htmlspecialchars($register_success) ?>
                        </div>
                    <?php endif; ?>
                    <form method="POST" action="">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                        <div class="form-group">
                            <label for="reg-name">Full Name</label>
                            <div class="input-wrap">
                                <input class="form-field" type="text" id="reg-name" name="fullname"
                                    minlength="5" maxlength="70"
                                    placeholder="Juan Dela Cruz"
                                    value="<?= htmlspecialchars($reg_fullname_val) ?>"
                                    autocomplete="name" required>
                                <i class="fa-solid fa-user input-icon-left"></i>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="reg-sid">Student ID</label>
                            <div class="input-wrap">
                                <input class="form-field" type="text" id="reg-sid" name="student_id"
                                    minlength="15" maxlength="15"
                                    placeholder="20XX-00XXX-BN-X"
                                    value="<?= htmlspecialchars($reg_studentid_val) ?>"
                                    title="Format: YYYY-XXXXX-BN-X"
                                    autocomplete="off" required>
                                <i class="fa-solid fa-id-card input-icon-left"></i>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="reg-email">Email</label>
                            <div class="input-wrap">
                                <input class="form-field" type="email" id="reg-email" name="email"
                                    minlength="15" maxlength="254"
                                    placeholder="youremail@student.edu.ph"
                                    value="<?= htmlspecialchars($reg_email_val) ?>"
                                    autocomplete="email" required>
                                <i class="fa-solid fa-envelope input-icon-left"></i>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="reg-pass">Password</label>
                                <div class="input-wrap">
                                    <input class="form-field" type="password" id="reg-pass" name="password"
                                        minlength="4" placeholder="Min. 4 characters"
                                        autocomplete="new-password" required>
                                    <i class="fa-solid fa-lock input-icon-left"></i>
                                    <button type="button" class="eye-toggle" data-target="reg-pass" tabindex="-1" aria-label="Show password">
                                        <i class="fa-regular fa-eye-slash"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="reg-cpass">Confirm</label>
                                <div class="input-wrap">
                                    <input class="form-field" type="password" id="reg-cpass" name="confirm_password"
                                        minlength="4" placeholder="Re-enter password"
                                        autocomplete="new-password" required>
                                    <i class="fa-solid fa-lock input-icon-left"></i>
                                    <button type="button" class="eye-toggle" data-target="reg-cpass" tabindex="-1" aria-label="Show password">
                                        <i class="fa-regular fa-eye-slash"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <button type="submit" name="register" class="btn-auth btn-auth--register">
                            <i class="fa-solid fa-user-plus"></i>
                            Create Account
                        </button>
                    </form>
                    <div class="modal-footer-link">
                        <span>Have an account?</span>
                        <button id="goToLogin">Sign in here</button>
                    </div>
                </div>

            </div><!-- /modal-body -->
        </div><!-- /modal-card -->
    </div><!-- /modal-overlay -->


    <script src="js/landing-page.js" nonce="<?= htmlspecialchars($csp_nonce) ?>"></script>
    <script nonce="<?= htmlspecialchars($csp_nonce) ?>">
        /* ================================================================
           INIT — PHP-generated values injected here
        ================================================================ */
        switchTab('<?= $open_tab ?>');
        <?php if ($auto_open_modal): ?>
            window.addEventListener('DOMContentLoaded', () => openModal('<?= $open_tab ?>'));
        <?php endif; ?>
        <?php if (!empty($register_success)): ?>
            setTimeout(() => switchTab('login'), 2200);
        <?php endif; ?>
    </script>


</body>

</html>