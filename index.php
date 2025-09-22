<?php
session_start();
$isLoggedIn = isset($_SESSION['user']);
$userFirstName = $isLoggedIn ? $_SESSION['user']['user_FN'] : '';

// Connection
require_once __DIR__ . '/admin/database/db_connect.php';
$db = new Database();
$pdo = $db->opencon();
$productStatuses = [];
$allProducts = [];
$stmt = $pdo->prepare("SELECT * FROM products WHERE status = 'active'");
$stmt->execute();
$allProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);
$productPrices = [];
foreach ($allProducts as $row) {
    $productStatuses[$row['product_id']] = $row['status'];
}

// Fetch active size prices map once: [product_id => ['grande'=>float, 'supreme'=>float]]
try {
    $sizePriceMap = method_exists($db, 'get_all_size_prices_for_active') ? $db->get_all_size_prices_for_active() : [];
} catch (Throwable $e) {
    $sizePriceMap = [];
}

// Fetch pastry variants map: [product_id => [ {variant_id,label,price}, ... ]]
try {
    $pastryVariantsMap = method_exists($db, 'get_all_pastry_variants') ? $db->get_all_pastry_variants() : [];
} catch (Throwable $e) {
    $pastryVariantsMap = [];
}

// Derive a base price per product from sizePriceMap (prefer grande, then supreme)
foreach ($allProducts as $row) {
    $pid = $row['product_id'];
    $gr = isset($sizePriceMap[$pid]['grande']) ? (float)$sizePriceMap[$pid]['grande'] : null;
    $su = isset($sizePriceMap[$pid]['supreme']) ? (float)$sizePriceMap[$pid]['supreme'] : null;
    $productPrices[$pid] = $gr !== null ? $gr : ($su !== null ? $su : 0.0);
}

$promoStmt = $pdo->prepare("SELECT * FROM promos WHERE active = 1 ORDER BY promo_id ASC");
$promoStmt->execute();
$activePromos = $promoStmt->fetchAll(PDO::FETCH_ASSOC);

// Helper: compute typical category header prices (Grande/Supreme) from DB
// Uses mode (most common) price in the category as Grande; Supreme = Grande + 30 by default.
// Falls back to provided defaults if no products found.
function computeCategoryHeader(array $allProducts, int $categoryId, int $defaultGrande, int $defaultSupreme): array {
    // Use sizePriceMap if available for more accuracy
    global $sizePriceMap;
    $prices = [];
    foreach ($allProducts as $p) {
        if ((int)($p['category_id'] ?? 0) === $categoryId && ($p['status'] ?? '') === 'active') {
            $pid = $p['product_id'];
            $pr = isset($sizePriceMap[$pid]['grande'])
                ? (float)$sizePriceMap[$pid]['grande']
                : (isset($p['price']) ? (float)$p['price'] : 0);
            if ($pr > 0) {
                $prices[] = (int)round($pr);
            }
        }
    }
    if (!$prices) {
        return ['grande' => $defaultGrande, 'supreme' => $defaultSupreme];
    }
    // mode (most frequent) price
    $freq = [];
    foreach ($prices as $pr) { $freq[$pr] = ($freq[$pr] ?? 0) + 1; }
    arsort($freq);
    $grande = (int)array_key_first($freq);
    // Try to infer a representative Supreme from products if any; otherwise, fall back to default delta
    $supCandidates = [];
    foreach ($allProducts as $p) {
        if ((int)($p['category_id'] ?? 0) === $categoryId && ($p['status'] ?? '') === 'active') {
            $pid = $p['product_id'];
            if (isset($sizePriceMap[$pid]['supreme'])) {
                $supCandidates[] = (int)round((float)$sizePriceMap[$pid]['supreme']);
            }
        }
    }
    if ($supCandidates) {
        // use mode of collected supreme prices
        $sf = [];
        foreach ($supCandidates as $sp) { $sf[$sp] = ($sf[$sp] ?? 0) + 1; }
        arsort($sf);
        $supreme = (int)array_key_first($sf);
    } else {
        $delta = max(0, (int)$defaultSupreme - (int)$defaultGrande);
        $supreme = $grande + $delta; // fallback: apply default delta
    }
    return ['grande' => $grande, 'supreme' => $supreme];
}


?>
<!DOCTYPE html>
<html lang="en">

<head>
    
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cups & Cuddles </title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="shortcut icon" href="img/logo.png" type="image/png">
    <link rel="stylesheet" href="./css/style.css">
</head>

<?php if (!Database::isSuperAdmin()): ?>
    <style>
        .topping-force-delete,
        .topping-force-delete-btn,
        .btn-force-delete {
            display: none !important;
            visibility: hidden !important;
        }
    </style>
<?php endif; ?>

<script>
    // Expose active size prices to the storefront JS for universal access
    window.SIZE_PRICE_MAP = <?php
        $safeMap = [];
        if (!empty($sizePriceMap)) {
            foreach ($sizePriceMap as $pid => $sizes) {
                $safeMap[$pid] = [
                    'grande' => isset($sizes['grande']) ? (float)$sizes['grande'] : null,
                    'supreme' => isset($sizes['supreme']) ? (float)$sizes['supreme'] : null,
                ];
            }
        }
        echo json_encode($safeMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    ?>;
    window.PASTRY_VARIANTS_MAP = <?php echo json_encode($pastryVariantsMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
</script>

<body>
    <!-- Header -->
    <header class="header">
        <div class="header-content">
            <div class="logo">C&C</div>
            <button class="hamburger-menu">
                <i class="fas fa-bars"></i>
            </button>
            <nav class="nav-menu">
                <a href="#" class="nav-item active" onclick="showSection('home')">Home</a>
                <a href="#" class="nav-item" onclick="showSection('about')">About </a>
                <a href="#" class="nav-item" onclick="showSection('products')">Shop</a>
                <a href="#" class="nav-item" onclick="showSection('locations')">Locations</a>


                <div class="profile-dropdown">
                    <button class="profile-btn" id="profileDropdownBtn" onclick="toggleProfileDropdown(event)">
                        <span class="profile-initials">
                            <?php if ($isLoggedIn): ?>
                                <?php echo htmlspecialchars(mb_substr($userFirstName, 0, 1)); ?>
                            <?php else: ?>
                                <i class="fas fa-user"></i>
                            <?php endif; ?>
                        </span>

                        <i class="fas fa-caret-down ms-1"></i>
                    </button>
                    <div class="profile-dropdown-menu" id="profileDropdownMenu">
                        <?php if ($isLoggedIn): ?>
                            <a href="#" class="dropdown-item" onclick="showEditProfileModal(); event.stopPropagation(); return false;">Edit Profile</a>
                            <a href="order_history.php" class="dropdown-item">Order History</a>
                            <a href="#" class="dropdown-item" onclick="logout(event); return false;">Logout</a>
                        <?php else: ?>
                            <a href="#" class="dropdown-item" onclick="showLoginModal(); event.stopPropagation(); return false;">Sign In</a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($isLoggedIn): ?>
                    <span class="navbar-username" style="margin-left:10px;font-weight:600;">
                        <?php echo htmlspecialchars($userFirstName); ?>
                    </span>
                <?php endif; ?>
            </nav>
        </div>
    </header>

    <!-- Login Modal -->
    <div id="loginModal" class="auth-modal">
        <div class="auth-content">
            <button class="close-auth" onclick="closeAuthModal()">
                <i class="fas fa-times"></i>
            </button>
            <div class="auth-header">
                <h3>Welcome Back!</h3>
                <p>Sign in to your Cups & Cuddles account</p>
            </div>
            <div id="loginSuccess" class="success-message">
                <i class="fas fa-check-circle"></i>
                Welcome back! You're now signed in.
            </div>
            <form class="auth-form" onsubmit="handleLogin(event); return false;">
                <div class="form-group">
                    <label>Email</label>
                    <input type="text" id="loginEmail" placeholder="Enter your email" required>
                    <div id="loginEmailError" class="text-danger small"></div>
                </div>
                <div class="form-group">
                    <label>Password</label>
                    <input type="password" id="loginPassword" placeholder="Enter your password" required>
                </div>
                <button type="submit" class="auth-btn" id="loginBtn">
                    <i class="fas fa-sign-in-alt"></i>
                    Sign In
                </button>
            </form>
            <div class="auth-switch">
                <p>New to Cups & Cuddles? <a onclick="switchToRegister()">Create an account</a></p>
            </div>
        </div>
    </div>

    <!-- Register Modal -->
    <div id="registerModal" class="auth-modal">
        <div class="auth-content">
            <button class="close-auth" onclick="closeAuthModal()">
                <i class="fas fa-times"></i>
            </button>
            <div class="auth-header">
                <h3>Join Us!</h3>
                <p>Create your account and start your coffee journey</p>
            </div>
            <div id="registerSuccess" class="success-message">
                <i class="fas fa-check-circle"></i>
                Account created! Welcome to Cups & Cuddles.
            </div>
            <form class="auth-form" id="registerForm" enctype="multipart/form-data" onsubmit="handleRegister(event); return false;">
                <div class="form-group" style="text-align:center;display:none;">
                </div>
                <div class="form-group">
                    <label>First Name</label>
                    <input type="text" name="registerName" id="registerName" placeholder="Enter your first name" required>
                    <div id="firstnameError" class="text-danger small"></div>
                </div>
                <div class="form-group">
                    <label>Last Name</label>
                    <input type="text" name="registerLastName" id="registerLastName" placeholder="Enter your last name" required>
                    <div id="lastnameError" class="text-danger small"></div>
                </div>
                <div class="form-group">
                    <label>Email Address</label>
                    <input type="email" name="registerEmail" id="registerEmail" placeholder="Enter your Email" required>
                    <div id="emailError" class="text-danger small"></div>
                </div>
                <div class="form-group password-wrapper">
                    <label for="registerPassword">Password</label>
                    <div class="input-with-toggle">
                        <input type="password" name="registerPassword" id="registerPassword"
                            class="password-field" placeholder="Create a secure password" required>
                       
                    </div>
                    <label class="note">Note: Capital Letter, Special Character and a Number is required</label>
                    <div id="passwordError" class="text-danger small"></div>
                </div>

                <div class="form-group password-wrapper">
                    <label for="confirmPassword">Confirm Password</label>
                    <div class="input-with-toggle">
                        <input type="password" name="confirmPassword" id="confirmPassword"
                            class="password-field" placeholder="Confirm your password" required>
                    </div>
                    <div id="confirmPasswordError" class="text-danger small"></div>
                </div>


                <div class="form-group" style="margin-bottom: 8px; display: flex; align-items: flex-start; justify-content: flex-start;">
                    <label for="acceptTerms" style="font-size: 0.97em; display: flex; align-items: center; gap: 3px; margin-bottom: 0;">
                        <input type="checkbox" id="acceptTerms" required>
                        I accept the
                        <button type="button" id="showTermsBtn" style="background: none; border: none; color: #40534b; text-decoration: underline; cursor: pointer; padding: 0; font-size: 1em; margin: 0;">
                            Terms and Conditions
                        </button>
                    </label>
                </div>
                <button type="submit" class="auth-btn" id="registerBtn">
                    <i class="fas fa-user-plus"></i>
                    Create Account
                </button>
            </form>
            <div class="auth-switch">
                <p>Already have an account? <a onclick="switchToLogin()">Sign in here</a></p>
            </div>
        </div>
    </div>

    <!-- Terms and Conditions Modal -->
    <div id="termsModal" class="auth-modal" style="z-index:4000;">
        <div class="auth-content" style="max-width:600px;">
            <button class="close-auth" onclick="document.getElementById('termsModal').classList.remove('active')">
                <i class="fas fa-times"></i>
            </button>
            <div class="auth-header">
                <h3>Terms and Conditions</h3>
            </div>
            <div style="max-height:50vh;overflow-y:auto;text-align:left;font-size:1em;color:#374151;padding-bottom:12px;">
                <p>
                    Welcome to Cups & Cuddles! By creating an account, you agree to the following terms:
                </p>
                <ul style="padding-left:18px;">
                    <li>Your information will be used for order processing and account management.</li>
                    <li>We will not share your personal data with third parties except as required by law.</li>
                    <li>You are responsible for keeping your account credentials secure.</li>
                    <li>All purchases are subject to our shop policies and operating hours.</li>
                    <li>We reserve the right to update these terms at any time.</li>
                </ul>
                <p>
                    For questions, please contact us at our socials</a>.
                </p>
            </div>
            <div style="text-align:center;">
                <button class="auth-btn" type="button" onclick="document.getElementById('termsModal').classList.remove('active')">Close</button>
            </div>
        </div>
    </div>

    <!-- Home Section -->
    <div id="home" class="section-content home-section">
        <section class="hero-section">
            <div class="coffee-bean"></div>
            <div class="coffee-bean"></div>
            <div class="coffee-bean"></div>
            <div class="coffee-bean"></div>
            <div class="coffee-bean"></div>
            <div class="coffee-bean"></div>

            <div class="hero-content">
                <h1>CUPS</h1>
                <h3>&</h3>

            </div>
            <div class="hero-content2">
                <h2>CUDDLES</h2>
            </div>
            <div class="coffee-image">
                <img src="img/cupss.png" alt="Iced Coffee">
            </div>

        </section>


        <section class="cards-section">
            <div class="cards-grid">
                <div class="card card-orange">
                    <img src="img/pic1.jpg" alt="Delicious Pastry">
                </div>
                <div class="card card-green">
                    <img src="img/blend.jpg" alt="Delicious Pastry">
                </div>
            </div>
        </section>

        <section class="cards-section">
            <div class="cards-grid2">
                <div class="card card-orange2 position-relative overflow-hidden">
                    <img src="img/first.jpg" alt="Delicious Pastry" class="img-fluid w-100 h-auto">
                    <div class="circle-wrapper position-absolute top-50 start-50 translate-middle">
                        <div class="circle-bg"></div>
                        <div class="center-icon">♥</div>
                        <svg viewBox="0 0 200 200" class="rotating-text">
                            <defs>
                                <path
                                    id="circlePath"
                                    d="M 100, 100 m -75, 0 a 75,75 0 1,1 150,0 a 75,75 0 1,1 -150,0" />
                            </defs>
                            <text>
                                <textPath href="#circlePath" startOffset="0%">
                                    • GO - TO • MOBILE • CAFE • IN CALABARZON
                                </textPath>
                            </text>
                        </svg>
                    </div>
                </div>

                <div class="card card-green2">
                    <img src="img/pic2.jpg" alt="Delicious Pastry">
                </div>
            </div>
        </section>

        <section class="impact-stories">
            <div class="section-header">
                <h2>Start Your Own Coffee Business: the Cups and Cuddles way! ☕︎</h2>
                <p>Turn your love for coffee into a thriving business today! Message our socials to know more and get started! 📨</p>
            </div>
            <div class="fade-left"></div>
            <div class="fade-right"></div>


            <div class="carousel-track">
                <?php
                if (!empty($activePromos)) {
                    foreach ($activePromos as $promo) {
                        $id = intval($promo['promo_id']);
                        // use created_at as cache-buster when available
                        $ver = isset($promo['created_at']) ? (int) strtotime($promo['created_at']) : time();
                        $src = 'admin/serve_promo.php?promo_id=' . $id . '&v=' . $ver;

                        $title = htmlspecialchars($promo['title'] ?? 'Promo');
                        echo '<div class="testimonial"><div class="testimonial-header">';
                        echo '<img src="' . htmlspecialchars($src) . '" alt="' . $title . '" class="testimonial-img" onclick="openTestimonialModal(this)">';
                        echo '</div></div>';
                    }
                } else {
                    echo '<div class="testimonial"><div class="testimonial-header"><img src="img/promo1.jpg" alt="Promo 1" class="testimonial-img" onclick="openTestimonialModal(this)"></div></div>';
                }
                ?>
            </div>
    </div>
    </section>



    <!-- About Section -->
    <div id="about" class="section-content about-section">
        <section class="about-hero-header position-relative overflow-hidden">
            <div class="about-hero-overlay"></div>
            <div class="container-fluid h-100">
                <div class="row h-100 align-items-center justify-content-center text-center text-white">
                    <div class="col-12">
                        <h1 class="about-hero-title">ABOUT US</h1>
                        <p class="about-hero-subtitle">The go-to mobile cafe around Calabarzon ✨🤍
                            Premium artisan beverages. Great Chat. Friendly Baristas.</p>
                    </div>
                </div>
            </div>

            <!-- Floating coffee beans -->
            <div class="about-floating-bean about-bean-1"></div>
            <div class="about-floating-bean about-bean-2"></div>
            <div class="about-floating-bean about-bean-3"></div>
        </section>

        <div class="container-fluid px-4 py-5">
            <!-- Our Story Section -->
            <section class="about-story-section py-5">
                <div class="container">
                    <div class="row align-items-center g-5">
                        <div class="col-lg-6">
                            <div class="about-image-container position-relative">
                                <div class="about-image-bg"></div>
                                <img src="img/pic1.jpg" alt="Coffee shop interior" class="about-main-image img-fluid rounded-4 shadow-lg">
                                <div class="about-floating-badge">
                                    <div class="d-flex align-items-center">
                                        <div class="about-badge-icon">
                                            <i class="fas fa-coffee"></i>
                                        </div>
                                        <div class="ms-3">
                                            <div class="about-badge-title">Est. 2024</div>
                                            <div class="about-badge-subtitle">Serving Excellence</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-6">
                            <div class="about-story-content">
                                <span class="about-section-badge">Our Story</span>
                                <h2 class="about-section-title mb-4">Let's connect over coffee?</h2>
                                <p class="about-story-text mb-4">
                                    At Cups and Cuddles, we’re more than just a mobile coffee shop — we’re a cozy experience on wheels.
                                    Founded with a passion for great coffee and warm connections, our mission is to bring handcrafted beverages and a welcoming atmosphere wherever we go.
                                </p>
                                <p class="about-story-text mb-4">
                                    Whether you’re starting your day or taking a much-needed break, our mobile café is your go-to spot for comforting cups and friendly vibes.
                                    Every brew is made with care, and every visit is a chance to slow down, sip, and smile.
                                </p>
                                <div class="d-flex align-items-center pt-3">
                                    <div class="about-avatar-group">
                                        <div class="about-avatar about-avatar-1"></div>
                                        <div class="about-avatar about-avatar-2"></div>
                                        <div class="about-avatar about-avatar-3"></div>
                                    </div>
                                    <small class="ms-3 text-muted fw-medium">Trusted by coffee lovers across Lipa — bringing warmth, one cup at a time.</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Values Section -->
            <section class="about-values-section py-5">
                <div class="container">
                    <div class="text-center mb-5">
                        <span class="about-section-badge about-amber">More About Us</span>
                        <h2 class="about-section-title mb-4">Why Cups and Cuddles?</h2>
                        <p class="about-section-subtitle mx-auto">
                            Every decision we make is guided by our core values that shape who we are and how we serve our community.
                        </p>
                    </div>

                    <div class="row g-4">
                        <div class="col-md-6 col-lg-4">
                            <div class="about-value-card">
                                <div class="about-value-icon about-red">
                                    <i class="fas fa-heart"></i>
                                </div>
                                <h3 class="about-value-title">Passion</h3>
                                <p class="about-value-description">We pour our heart into every cup, ensuring each sip brings warmth, joy, and a moment of comfort.</p>
                            </div>
                        </div>

                        <div class="col-md-6 col-lg-4">
                            <div class="about-value-card">
                                <div class="about-value-icon about-blue">
                                    <i class="fas fa-users"></i>
                                </div>
                                <h3 class="about-value-title">Community</h3>
                                <p class="about-value-description">We’re all about building connections — turning simple coffee moments into lasting relationships.</p>
                            </div>
                        </div>

                        <div class="col-md-6 col-lg-4">
                            <div class="about-value-card">
                                <div class="about-value-icon about-emerald">
                                    <i class="fas fa-award"></i>
                                </div>
                                <h3 class="about-value-title">Quality</h3>
                                <p class="about-value-description">From bean to cup, we uphold the highest standards to deliver consistently excellent coffee experiences.</p>
                            </div>
                        </div>

                        <div class="col-md-6 col-lg-4">
                            <div class="about-value-card">
                                <div class="about-value-icon about-green">
                                    <i class="fas fa-leaf"></i>
                                </div>
                                <h3 class="about-value-title">Sustainability</h3>
                                <p class="about-value-description">We believe great coffee shouldn't come at the planet’s expense — our practices support a greener future.</p>
                            </div>
                        </div>

                        <div class="col-md-6 col-lg-4">
                            <div class="about-value-card">
                                <div class="about-value-icon about-amber-icon">
                                    <i class="fas fa-coffee"></i>
                                </div>
                                <h3 class="about-value-title">Craftsmanship</h3>
                                <p class="about-value-description">Every drink is artfully crafted with skill, care, and creativity to elevate your daily coffee ritual.</p>
                            </div>
                        </div>

                        <div class="col-md-6 col-lg-4">
                            <div class="about-value-card">
                                <div class="about-value-icon about-purple">
                                    <i class="fas fa-clock"></i>
                                </div>
                                <h3 class="about-value-title">Consistency</h3>
                                <p class="about-value-description">You can count on us — same great taste, same cozy vibes, no matter where you find us.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Team Section -->
            <section class="about-team-section py-5">
                <div class="container">
                    <div class="about-team-container">
                        <div class="text-center mb-5">
                            <span class="about-section-badge about-emerald-badge">Meet Our Team</span>
                            <h2 class="about-section-title mb-4">The People Behind the Magic</h2>
                            <p class="about-section-subtitle mx-auto">
                                Our passionate team of coffee enthusiasts and hospitality experts work together to create exceptional experiences.
                            </p>
                        </div>
                        <div class="row g-5 justify-content-center">
                            <div class="col-md-4 d-flex justify-content-center">
                                <div class="about-team-member team-left text-center position-relative">
                                    <span class="team-bubble" aria-hidden="true"></span>
                                    <div class="about-member-image-container">
                                        <div class="about-member-image-bg"></div>
                                        <a href="https://web.facebook.com/Haze.Hyl" target="_blank" rel="noopener noreferrer">
                                            <img src="img/owner.jpg" alt="Hazel Anne Haylo" class="about-member-image">
                                        </a>
                                    </div>
                                    <h3 class="about-member-name">Hazel Anne Haylo</h3>
                                </div>
                            </div>
                            <div class="col-md-4 d-flex justify-content-center">
                                <div class="about-team-member team-right text-center position-relative">
                                    <span class="team-bubble" aria-hidden="true"></span>
                                    <div class="about-member-image-container">
                                        <div class="about-member-image-bg"></div>
                                        <a href="https://web.facebook.com/nebejewor" target="_blank" rel="noopener noreferrer">
                                            <img src="img/owner1.jpg" alt="Jeben Rowe Villaluz" class="about-member-image">
                                        </a>
                                    </div>
                                    <h3 class="about-member-name">Jeben Rowe Villaluz</h3>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Visit Us CTA -->
            <section class="about-cta-section py-5">
                <div class="container">
                    <div class="about-cta-container text-center text-white position-relative overflow-hidden">
                        <div class="about-cta-overlay"></div>
                        <div class="position-relative">
                            <h2 class="about-cta-title mb-4">Ready to Experience Cups and Cuddles?</h2>
                            <p class="about-cta-subtitle mb-5">
                                Visit us today and discover why we're more than just a coffee shop – Start your day with Cups and Cuddles.
                            </p>
                            <div class="d-flex flex-column flex-sm-row gap-3 justify-content-center">
                                <button class="btn btn-light btn-lg about-cta-btn-primary" onclick="showSection('locations')">Find Our Locations</button>
                                <button class="btn btn-outline-light btn-lg about-cta-btn-secondary" onclick="showSection('products')">View Our Menu</button>
                            </div>
                        </div>

                        <!-- Decorative elements -->
                        <div class="about-cta-decoration about-decoration-1"></div>
                        <div class="about-cta-decoration about-decoration-2"></div>
                        <div class="about-cta-decoration about-decoration-3"></div>
                        <div class="about-cta-decoration about-decoration-4"></div>
                    </div>
                </div>
            </section>
        </div>
    </div>

    <!-- Products Section -->
    <div id="products" class="section-content products-section">
        <section class="products-hero-header position-relative overflow-hidden">
            <div class="products-hero-overlay"></div>
            <div class="container-fluid h-100">
                <div class="row h-100 align-items-center justify-content-center text-center text-white">
                    <div class="col-12">
                        <h1 class="products-hero-title">Shop Now</h1>
                        <p class="products-hero-subtitle">Crafting moments, one cup at a time</p>
                    </div>
                </div>
            </div>

            <!-- Floating coffee beans -->
            <div class="products-floating-bean products-bean-1"></div>
            <div class="products-floating-bean products-bean-2"></div>
            <div class="products-floating-bean products-bean-3"></div>
        </section>


        <!-- Hot/Cold Drinks Toggle Buttons -->
        <div class="d-flex justify-content-center my-4">
            <button class="btn btn-outline-dark mx-2" id="hotDrinksBtn" onclick="filterDrinks('hot')">Hot Drinks</button>
            <button class="btn btn-outline-dark mx-2" id="coldDrinksBtn" onclick="filterDrinks('cold')">Cold Drinks</button>
            <button class="btn btn-outline-dark mx-2" id="pastriesBtn" onclick="filterDrinks('pastries')">Pastries</button>
        </div>

        <div class="products-header">
            <div class="delivery-badge">
                <i class="fas fa-truck"></i>
            </div>
            <h2>Roasted goodness to your doorstep!</h2>
        </div>

        <!-- Top Products Container -->
        <div id="topProductsContainer"></div>
        <!-- End Top Products Section -->

        <?php $hp5 = computeCategoryHeader($allProducts, 5, 140, 150); ?>
        <div class="products-header">
            <h3 style="font-size:2rem;font-weight:700;margin-bottom:0.5em;">Premium Coffee</h3>
            <div style="font-size:1.1rem;font-weight:500;margin-bottom:1.5em;">
                <span>Grande - Php <?= htmlspecialchars($hp5['grande']) ?></span> &nbsp;|&nbsp; <span>Supreme - Php <?= htmlspecialchars($hp5['supreme']) ?></span>
            </div>
        </div>
        <div class="product-list">
            <?php
            $shownIds = [];
            $premiumIndex = 0;
            foreach ($allProducts as $product) {
                if (
                    isset($product['category_id']) && $product['category_id'] == 5 // Premium Coffee category_id
                    && $product['status'] === 'active'
                ) {
                    $shownIds[] = $product['product_id'];
                    $imgSrc = $product['image'];
                    if (strpos($imgSrc, 'img/') !== 0) {
                        $imgSrc = 'img/' . ltrim($imgSrc, '/');
                    }
                    $dataType = isset($product['data_type']) ? $product['data_type'] : 'cold';
            ?>
                    <div class="product-item card-premium-<?= $premiumIndex ?>" data-type="<?= $dataType ?>" data-category="5">
                        <div class="product-image">
                            <img src="<?= htmlspecialchars($imgSrc) ?>" alt="<?= htmlspecialchars($product['name']) ?>">
                        </div>
                        <div class="product-info">
                            <h3><?= htmlspecialchars($product['name']) ?></h3>
                            <span class="badge bg-success mb-2">Premium Coffee</span>
                            <p><?= htmlspecialchars($product['description']) ?></p>
                            <?php
                                $pid = $product['product_id'];
                                $grande = isset($sizePriceMap[$pid]['grande']) ? (float)$sizePriceMap[$pid]['grande'] : (isset($productPrices[$pid]) ? (float)$productPrices[$pid] : 0);
                                $supreme = isset($sizePriceMap[$pid]['supreme']) ? (float)$sizePriceMap[$pid]['supreme'] : $grande;
                            ?>
                            <button type="button" class="view-btn"
                                data-id="<?= htmlspecialchars($product['product_id'], ENT_QUOTES) ?>" data-product-id="<?= htmlspecialchars($product['product_id'], ENT_QUOTES) ?>"
                                data-name="<?= htmlspecialchars($product['name'], ENT_QUOTES) ?>"
                                data-price="<?= htmlspecialchars(number_format((float)$grande, 2, '.', ''), ENT_QUOTES) ?>"
                                data-grande="<?= htmlspecialchars(number_format((float)$grande, 2, '.', ''), ENT_QUOTES) ?>"
                                data-supreme="<?= htmlspecialchars(number_format((float)$supreme, 2, '.', ''), ENT_QUOTES) ?>"
                                data-desc="<?= htmlspecialchars($product['description'], ENT_QUOTES) ?>"
                                data-image="<?= htmlspecialchars($imgSrc, ENT_QUOTES) ?>"
                                data-type="<?= ($dataType === 'hot') ? 'hot' : 'cold' ?>">
                                View
                            </button>
                        </div>
                    </div>
                <?php
                    $premiumIndex++;
                }
            }
            $premiumProducts = [
                [
                    'product_id' => 'ameri',
                    'name' => 'Americano',
                    'cold_img' => 'img/ameri.jpg',
                    'hot_img' => 'img/HOT MARI.jpg',
                    'pastries_img' => 'img/egg pie.jpg',
                    'cold_desc' => 'A bold and simple espresso diluted with hot water for a smooth, black coffee.',
                    'hot_desc' => 'A strong espresso-based drink diluted with hot water; bold and smooth.',
                    'pastries_desc' => 'A delicious egg pie pastry, perfect for pairing with your coffee.',
                ],
                [
                    'product_id' => 'caramel-macchiato',
                    'name' => 'Caramel Macchiato',
                    'cold_img' => 'img/caramel.jpg',
                    'hot_img' => 'img/HOT MARI.jpg',
                    'cold_desc' => 'A layered espresso drink with milk and rich caramel drizzle.',
                    'hot_desc' => 'Steamed milk with espresso and a swirl of rich caramel sauce.',
                ],

            ];
            foreach ($premiumProducts as $p) {
                if (!in_array($p['product_id'], $shownIds) && (!isset($productStatuses[$p['product_id']]) || $productStatuses[$p['product_id']] === 'active')) {
                ?>
                    <div class="product-item" data-type="cold" data-category="5">
                        <div class="product-image">
                            <img src="<?= htmlspecialchars($p['cold_img']) ?>" alt="<?= htmlspecialchars($p['name']) ?>">
                        </div>
                        <div class="product-info">
                            <h3><?= htmlspecialchars($p['name']) ?></h3>
                            <span class="badge bg-success mb-2">Premium Coffee</span>
                            <p><?= htmlspecialchars($p['cold_desc']) ?></p>
                            <div class="product-footer">
                                <?php
                                    $pid2 = $p['product_id'];
                                    $base2 = isset($productPrices[$pid2]) ? (float)$productPrices[$pid2] : 120;
                                    $gr2 = isset($sizePriceMap[$pid2]['grande']) ? (float)$sizePriceMap[$pid2]['grande'] : $base2;
                                    $su2 = isset($sizePriceMap[$pid2]['supreme']) ? (float)$sizePriceMap[$pid2]['supreme'] : $gr2;
                                ?>
                                <button class="view-btn"
                                    data-id="<?= htmlspecialchars($p['product_id'], ENT_QUOTES) ?>" data-product-id="<?= htmlspecialchars($p['product_id'], ENT_QUOTES) ?>"
                                    data-name="<?= htmlspecialchars($p['name'], ENT_QUOTES) ?>"
                                    data-price="<?= htmlspecialchars($base2, ENT_QUOTES) ?>"
                                    data-grande="<?= htmlspecialchars(number_format($gr2, 2, '.', ''), ENT_QUOTES) ?>"
                                    data-supreme="<?= htmlspecialchars(number_format($su2, 2, '.', ''), ENT_QUOTES) ?>"
                                    data-desc="<?= htmlspecialchars($p['cold_desc'], ENT_QUOTES) ?>"
                                    data-image="<?= htmlspecialchars($p['cold_img'], ENT_QUOTES) ?>"
                                    data-type="cold">View</button>
                            </div>
                        </div>
                    </div>
                    <?php
                    ?>

                    <div class="product-item" data-type="hot" data-category="5">
                        <div class="product-image">
                            <img src="<?= htmlspecialchars($p['hot_img']) ?>" alt="<?= htmlspecialchars($p['name']) ?>">
                        </div>
                        <div class="product-info">
                            <h3><?= htmlspecialchars($p['name']) ?></h3>
                            <span class="badge bg-success mb-2">Premium Coffee</span>
                            <p><?= htmlspecialchars($p['hot_desc']) ?></p>
                            <div class="product-footer">
                                <?php
                                    $base3 = isset($productPrices[$pid2]) ? (float)$productPrices[$pid2] : 120;
                                    $gr3 = isset($sizePriceMap[$pid2]['grande']) ? (float)$sizePriceMap[$pid2]['grande'] : $base3;
                                    $su3 = isset($sizePriceMap[$pid2]['supreme']) ? (float)$sizePriceMap[$pid2]['supreme'] : $gr3;
                                ?>
                                <button class="view-btn"
                                    data-id="<?= htmlspecialchars($p['product_id'], ENT_QUOTES) ?>" data-product-id="<?= htmlspecialchars($p['product_id'], ENT_QUOTES) ?>"
                                    data-name="<?= htmlspecialchars($p['name'], ENT_QUOTES) ?>"
                                    data-price="<?= htmlspecialchars($base3, ENT_QUOTES) ?>"
                                    data-grande="<?= htmlspecialchars(number_format($gr3, 2, '.', ''), ENT_QUOTES) ?>"
                                    data-supreme="<?= htmlspecialchars(number_format($su3, 2, '.', ''), ENT_QUOTES) ?>"
                                    data-desc="<?= htmlspecialchars($p['hot_desc'], ENT_QUOTES) ?>"
                                    data-image="<?= htmlspecialchars($p['hot_img'], ENT_QUOTES) ?>"
                                    data-type="hot">View</button>
                            </div>
                        </div>
                    </div>
            <?php
                }
            }
            ?>
        </div>


        <!-- Pastries Section -->
        <div class="products-header" style="margin-top:2em;">
            <h3 style="font-size:2rem;font-weight:700;margin-bottom:0.5em;">Pastries</h3>
            <div style="font-size:1.1rem;font-weight:500;margin-bottom:1.5em;">Freshly baked, perfect with coffee</div>
        </div>

        <div class="pastries-section">
            <div class="product-list">
                <?php
                foreach ($allProducts as $product) {
                    if (isset($product['category_id']) && (int)$product['category_id'] === 7 && $product['status'] === 'active') {
                        $imgSrc = trim($product['image'] ?? '');
                        if (!preg_match('#^https?://#i', $imgSrc)) {
                            $imgSrc = ltrim($imgSrc, '/');
                            if (strpos($imgSrc, 'img/') !== 0) $imgSrc = 'img/' . $imgSrc;
                        }
                        $fsPath = __DIR__ . '/' . ltrim(parse_url($imgSrc, PHP_URL_PATH), '/');
                        if (!file_exists($fsPath)) $imgSrc = 'img/placeholder_pastry.png';

                        // Prefer DB-driven pastry variants if available; fallback to simple heuristics
                        $pid = $product['product_id'];
                        $variants = [];
                        if (!empty($pastryVariantsMap[$pid]) && is_array($pastryVariantsMap[$pid])) {
                            $variants = $pastryVariantsMap[$pid];
                        } else {
                            $nameLc = mb_strtolower($product['name']);
                            if (strpos($nameLc, 'crème flan') !== false || strpos($nameLc, 'creme flan') !== false || strpos($nameLc, 'flan') !== false) {
                                $variants = [
                                    ['label' => 'Per piece', 'price' => 60],
                                    ['label' => 'Box of 4', 'price' => 230],
                                    ['label' => 'Box of 6', 'price' => 350],
                                ];
                            } elseif (strpos($nameLc, 'egg pie') !== false) {
                                $variants = [
                                    ['label' => 'Per slice', 'price' => 60],
                                    ['label' => 'Whole', 'price' => 380],
                                ];
                            }
                        }

                        // Determine display/base price: use minimum variant price when variants exist; otherwise fallback
                        $basePrice = 0;
                        if (!empty($variants)) {
                            $prices = array_map(function($v) { return isset($v['price']) ? (float)$v['price'] : 0; }, $variants);
                            $prices = array_values(array_filter($prices, function($p){ return $p > 0; }));
                            if (!empty($prices)) {
                                $basePrice = min($prices);
                            }
                        }
                        if ($basePrice <= 0) {
                            $fallbackPid = $pid;
                            $fallback = 0;
                            if (isset($productPrices[$fallbackPid])) {
                                $fallback = (float)$productPrices[$fallbackPid];
                            } elseif (isset($product['price'])) {
                                $fallback = (float)$product['price'];
                            }
                            $basePrice = $fallback > 0 ? $fallback : 0;
                        }
                ?>
                        <div class="product-item" data-type="pastries" data-category="7">
                            <div class="product-image">
                                <img src="<?= htmlspecialchars($imgSrc) ?>" alt="<?= htmlspecialchars($product['name']) ?>">
                            </div>
                            <div class="product-info">
                                <h3><?= htmlspecialchars($product['name']) ?></h3>
                                <span class="badge bg-success mb-2">Pastries</span>
                                <p><?= htmlspecialchars($product['description']) ?></p>
                                <div class="product-price" style="font-weight:700;color:#2d4a3a;margin-bottom:6px;">
                                    <?= $basePrice > 0 ? 'From ₱' . number_format($basePrice, 2) : '' ?>
                                </div>
                                <div class="product-footer">
                                    <?php
                                    // Prepare variants JSON for data-attribute
                                    $variants_json = !empty($variants)
                                        ? json_encode($variants, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
                                        : 'null';
                                    $basePrice = isset($basePrice) ? $basePrice : 0;
                                    $imgSrc = isset($imgSrc) ? $imgSrc : '';
                                    ?>
                                    <button type="button" class="view-btn"
                                        data-id="<?= htmlspecialchars($product['product_id'], ENT_QUOTES) ?>"
                                        data-name="<?= htmlspecialchars($product['name'], ENT_QUOTES) ?>"
                                        data-price="<?= htmlspecialchars($basePrice, ENT_QUOTES) ?>"
                                        data-desc="<?= htmlspecialchars($product['description'], ENT_QUOTES) ?>"
                                        data-image="<?= htmlspecialchars($imgSrc, ENT_QUOTES) ?>"
                                        data-type="pastries"
                                        data-variants='<?= htmlspecialchars($variants_json, ENT_QUOTES) ?>'>
                                        View
                                    </button>
                                </div>
                            </div>
                        </div>
                <?php
                    }
                }
                ?>

            </div>
        </div>


        <!-- Specialty Coffee Section -->
        <?php $hp6 = computeCategoryHeader($allProducts, 6, 150, 180); ?>
        <div class="products-header" style="margin-top:2em;">
            <h3 style="font-size:2rem;font-weight:700;margin-bottom:0.5em;">Specialty Coffee</h3>
            <div style="font-size:1.1rem;font-weight:500;margin-bottom:1.5em;">
                <span>Grande - Php <?= htmlspecialchars($hp6['grande']) ?></span> &nbsp;|&nbsp; <span>Supreme - Php <?= htmlspecialchars($hp6['supreme']) ?></span>
            </div>
        </div>
        <div class="product-list">
            <?php
            $shownIds = [];
            foreach ($allProducts as $product) {
                if (
                    isset($product['category_id']) && $product['category_id'] == 6 // Premium Coffee category_id
                    && $product['status'] === 'active'
                ) {
                    $shownIds[] = $product['product_id']; // changed here
                    $imgSrc = $product['image'];
                    if (strpos($imgSrc, 'img/') !== 0) {
                        $imgSrc = 'img/' . ltrim($imgSrc, '/');
                    }
                    $dataType = isset($product['data_type']) ? $product['data_type'] : 'cold';
            ?>

                    <div class="product-item card-premium-<?= $premiumIndex ?>" data-type="<?= $dataType ?>" data-category="6">
                        <div class="product-image">
                            <img src="<?= htmlspecialchars($imgSrc) ?>" alt="<?= htmlspecialchars($product['name']) ?>">
                        </div>
                        <div class="product-info">
                            <h3><?= htmlspecialchars($product['name']) ?></h3>
                            <span class="badge bg-success mb-2">Specialty Coffee</span>
                            <p><?= htmlspecialchars($product['description']) ?></p>
                            <?php
                                $pid = $product['product_id'];
                                $base = isset($productPrices[$pid]) ? (float)$productPrices[$pid] : 0;
                            ?>
                            <button type="button" class="view-btn"
                                data-id="<?= htmlspecialchars($product['product_id'], ENT_QUOTES) ?>"
                                data-name="<?= htmlspecialchars($product['name'], ENT_QUOTES) ?>"
                                data-price="<?= htmlspecialchars(number_format($base, 2, '.', ''), ENT_QUOTES) ?>"
                                data-desc="<?= htmlspecialchars($product['description'], ENT_QUOTES) ?>"
                                data-image="<?= htmlspecialchars($imgSrc, ENT_QUOTES) ?>"
                                data-type="<?= ($dataType === 'hot') ? 'hot' : 'cold' ?>">
                                View
                            </button>
                        </div>
                    </div>
            <?php
                    $premiumIndex++;
                }
            }
            ?>
        </div>


        <!-- Chocolate Overload Section -->
        <?php $hp2 = computeCategoryHeader($allProducts, 2, 150, 180); ?>
        <div class="products-header">
            <h3 style="font-size:2rem;font-weight:700;margin-bottom:0.5em;">Chocolate Overload</h3>
            <div style="font-size:1.1rem;font-weight:500;margin-bottom:1.5em;">
                <span>Grande - Php <?= htmlspecialchars($hp2['grande']) ?></span> &nbsp;|&nbsp; <span>Supreme - Php <?= htmlspecialchars($hp2['supreme']) ?></span>
            </div>
        </div>
        <div class="product-list">
            <?php
            $shownIds = [];
            foreach ($allProducts as $product) {
                if (
                    isset($product['category_id']) && $product['category_id'] == 2 // Premium Coffee category_id
                    && $product['status'] === 'active'
                ) {
                    $shownIds[] = $product['product_id'];
                    $imgSrc = $product['image'];
                    if (strpos($imgSrc, 'img/') !== 0) {
                        $imgSrc = 'img/' . ltrim($imgSrc, '/');
                    }
                    $dataType = isset($product['data_type']) ? $product['data_type'] : 'cold';
            ?>
                    <div class="product-item card-premium-<?= $premiumIndex ?>" data-type="<?= $dataType ?>" data-category="2">
                        <div class="product-image">
                            <img src="<?= htmlspecialchars($imgSrc) ?>" alt="<?= htmlspecialchars($product['name']) ?>">
                        </div>
                        <div class="product-info">
                            <h3><?= htmlspecialchars($product['name']) ?></h3>
                            <span class="badge bg-success mb-2">Chocolate Overload</span>
                            <p><?= htmlspecialchars($product['description']) ?></p>
                            <?php $base = isset($productPrices[$product['product_id']]) ? (float)$productPrices[$product['product_id']] : 0; ?>
                            <button type="button" class="view-btn"
                                data-id="<?= htmlspecialchars($product['product_id'], ENT_QUOTES) ?>"
                                data-name="<?= htmlspecialchars($product['name'], ENT_QUOTES) ?>"
                                data-price="<?= htmlspecialchars(number_format($base, 2, '.', ''), ENT_QUOTES) ?>"
                                data-desc="<?= htmlspecialchars($product['description'], ENT_QUOTES) ?>"
                                data-image="<?= htmlspecialchars($imgSrc, ENT_QUOTES) ?>"
                                data-type="<?= ($dataType === 'hot') ? 'hot' : 'cold' ?>">
                                View
                            </button>
                        </div>
                    </div>
            <?php
                    $premiumIndex++;
                }
            }
            ?>
        </div>

        <!-- Matcha Series Section -->
        <?php $hp3 = computeCategoryHeader($allProducts, 3, 160, 190); ?>
        <div class="products-header" style="margin-top:2em;">
            <h3 style="font-size:2rem;font-weight:700;margin-bottom:0.5em;">Matcha Series</h3>
            <div style="font-size:1.1rem;font-weight:500;margin-bottom:1.5em;">
                <span>Grande - Php <?= htmlspecialchars($hp3['grande']) ?></span> &nbsp;|&nbsp; <span>Supreme - Php <?= htmlspecialchars($hp3['supreme']) ?></span>
            </div>
        </div>
        <div class="product-list">
            <?php
            $shownIds = [];
            foreach ($allProducts as $product) {
                if (
                    isset($product['category_id']) && $product['category_id'] == 3 // Premium Coffee category_id
                    && $product['status'] === 'active'
                ) {
                    $shownIds[] = $product['product_id'];
                    $imgSrc = $product['image'];
                    if (strpos($imgSrc, 'img/') !== 0) {
                        $imgSrc = 'img/' . ltrim($imgSrc, '/');
                    }
                    $dataType = isset($product['data_type']) ? $product['data_type'] : 'cold';
            ?>
                    <div class="product-item card-premium-<?= $premiumIndex ?>" data-type="<?= $dataType ?>" data-category="3">
                        <div class="product-image">
                            <img src="<?= htmlspecialchars($imgSrc) ?>" alt="<?= htmlspecialchars($product['name']) ?>">
                        </div>
                        <div class="product-info">
                            <h3><?= htmlspecialchars($product['name']) ?></h3>
                            <span class="badge bg-success mb-2">Matcha Series</span>
                            <p><?= htmlspecialchars($product['description']) ?></p>
                            <button type="button" class="view-btn"
                                data-id="<?= htmlspecialchars($product['product_id'], ENT_QUOTES) ?>"
                                data-name="<?= htmlspecialchars($product['name'], ENT_QUOTES) ?>"
                                data-price="<?= htmlspecialchars(isset($product['price']) ? $product['price'] : 0, ENT_QUOTES) ?>"
                                data-desc="<?= htmlspecialchars($product['description'], ENT_QUOTES) ?>"
                                data-image="<?= htmlspecialchars($imgSrc, ENT_QUOTES) ?>"
                                data-type="<?= ($dataType === 'hot') ? 'hot' : 'cold' ?>">
                                View
                            </button>
                        </div>
                    </div>
            <?php
                    $premiumIndex++;
                }
            }
            ?>
        </div>

        <!-- Milk Based Section -->
        <?php $hp4 = computeCategoryHeader($allProducts, 4, 99, 120); ?>
        <div class="products-header" style="margin-top:2em;">
            <h3 style="font-size:2rem;font-weight:700;margin-bottom:0.5em;">Milk Based</h3>
            <div style="font-size:1.1rem;font-weight:500;margin-bottom:1.5em;">
                <span>Grande - Php <?= htmlspecialchars($hp4['grande']) ?></span> &nbsp;|&nbsp; <span>Supreme - Php <?= htmlspecialchars($hp4['supreme']) ?></span>
            </div>
        </div>
        <div class="product-list">
            <?php
            $shownIds = [];
            foreach ($allProducts as $product) {
                if (
                    isset($product['category_id']) && $product['category_id'] == 4 // Premium Coffee category_id
                    && $product['status'] === 'active'
                ) {
                    $shownIds[] = $product['product_id'];
                    $imgSrc = $product['image'];
                    if (strpos($imgSrc, 'img/') !== 0) {
                        $imgSrc = 'img/' . ltrim($imgSrc, '/');
                    }
                    $dataType = isset($product['data_type']) ? $product['data_type'] : 'cold';
            ?>
                    <div class="product-item card-premium-<?= $premiumIndex ?>" data-type="<?= $dataType ?>" data-category="4">
                        <div class="product-image">
                            <img src="<?= htmlspecialchars($imgSrc) ?>" alt="<?= htmlspecialchars($product['name']) ?>">
                        </div>
                        <div class="product-info">
                            <h3><?= htmlspecialchars($product['name']) ?></h3>
                            <span class="badge bg-success mb-2">Milk Based</span>
                            <p><?= htmlspecialchars($product['description']) ?></p>
                            <button type="button" class="view-btn"
                                data-id="<?= htmlspecialchars($product['product_id'], ENT_QUOTES) ?>"
                                data-name="<?= htmlspecialchars($product['name'], ENT_QUOTES) ?>"
                                data-price="<?= htmlspecialchars(isset($product['price']) ? $product['price'] : 0, ENT_QUOTES) ?>"
                                data-desc="<?= htmlspecialchars($product['description'], ENT_QUOTES) ?>"
                                data-image="<?= htmlspecialchars($imgSrc, ENT_QUOTES) ?>"
                                data-type="<?= ($dataType === 'hot') ? 'hot' : 'cold' ?>">
                                View
                            </button>
                        </div>
                    </div>
            <?php
                    $premiumIndex++;
                }
            }
            ?>
        </div>

        <!-- All Time Fave Section -->
        <?php $hp1 = computeCategoryHeader($allProducts, 1, 120, 170); ?>
        <div class="products-header" style="margin-top:2em;">
            <h3 style="font-size:2rem;font-weight:700;margin-bottom:0.5em;">All Time Fave</h3>
            <div style="font-size:1.1rem;font-weight:500;margin-bottom:1.5em;">
                <span>Grande - Php <?= htmlspecialchars($hp1['grande']) ?></span> &nbsp;|&nbsp; <span>Supreme - Php <?= htmlspecialchars($hp1['supreme']) ?></span>
            </div>
        </div>
        <div class="product-list">
            <?php
            $shownIds = [];
            foreach ($allProducts as $product) {
                if (
                    isset($product['category_id']) && $product['category_id'] == 1 // Premium Coffee category_id
                    && $product['status'] === 'active'
                ) {
                    $shownIds[] = $product['product_id'];
                    $imgSrc = $product['image'];
                    if (strpos($imgSrc, 'img/') !== 0) {
                        $imgSrc = 'img/' . ltrim($imgSrc, '/');
                    }
                    $dataType = isset($product['data_type']) ? $product['data_type'] : 'cold';
            ?>
                    <div class="product-item card-premium-<?= $premiumIndex ?>" data-type="<?= $dataType ?>" data-category="1">
                        <div class="product-image">
                            <img src="<?= htmlspecialchars($imgSrc) ?>" alt="<?= htmlspecialchars($product['name']) ?>">
                        </div>
                        <div class="product-info">
                            <h3><?= htmlspecialchars($product['name']) ?></h3>
                            <span class="badge bg-success mb-2">All Time Fav</span>
                            <p><?= htmlspecialchars($product['description']) ?></p>
                            <button type="button" class="view-btn"
                                data-id="<?= htmlspecialchars($product['product_id'], ENT_QUOTES) ?>"
                                data-name="<?= htmlspecialchars($product['name'], ENT_QUOTES) ?>"
                                data-price="<?= htmlspecialchars(isset($product['price']) ? $product['price'] : 0, ENT_QUOTES) ?>"
                                data-desc="<?= htmlspecialchars($product['description'], ENT_QUOTES) ?>"
                                data-image="<?= htmlspecialchars($imgSrc, ENT_QUOTES) ?>"
                                data-type="<?= ($dataType === 'hot') ? 'hot' : 'cold' ?>">
                                View
                            </button>
                        </div>
                    </div>
            <?php
                    $premiumIndex++;
                }
            }
            ?>
        </div>
    </div>
    <!-- Locations Section -->
    <div id="locations" class="section-content location-section">
        <section class="locations-hero-header position-relative overflow-hidden">
            <div class="locations-hero-overlay"></div>
            <div class="container-fluid h-100">
                <div class="row h-100 align-items-center justify-content-center text-center text-white">
                    <div class="col-12">
                        <h1 class="locations-hero-title">Our Locations</h1>
                        <p class="locations-hero-subtitle">Our aim is to promote local business tourism on undiscovered spots around Calabarzon</p>
                    </div>
                </div>
            </div>
            <!-- Floating coffee beans -->
            <div class="locations-floating-bean locations-bean-1"></div>
            <div class="locations-floating-bean locations-bean-2"></div>
            <div class="locations-floating-bean locations-bean-3"></div>
        </section>


        <?php
        require_once __DIR__ . '/admin/database/db_connect.php';
        $db = new Database();
        $pdo = $db->opencon();
        $locations = [];
        // Only show locations that are open to the public (case-insensitive)
        $stmt = $pdo->prepare("SELECT * FROM locations WHERE LOWER(status) = 'open' ORDER BY location_id ASC");
        $stmt->execute();
        $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        ?>

        <?php foreach ($locations as $loc): ?>
            <div class="container my-5">
                <div class="row bg-light rounded-4 shadow-sm overflow-hidden">
                    <div class="col-md-6 p-0">
                        <img src="<?= !empty($loc['image']) ? htmlspecialchars($loc['image']) : 'img/placeholder.png' ?>"
                            alt="<?= htmlspecialchars($loc['name']) ?>" class="img-fluid h-100 w-100 object-fit-cover">
                    </div>
                    <div class="col-md-6 d-flex flex-column justify-content-center p-5">
                        <small class="text-muted">Lipa City</small>
                        <h1 class="fw-bold">Batangas</h1>
                        <ul class="list-unstyled mt-4 mb-4">
                            <li class="mb-2"><i class="fas fa-map-marker-alt me-2"></i><?= htmlspecialchars($loc['name']) ?></li>
                            <li class="mb-2"><i class="fas fa-clock me-2"></i>3:00 PM - 9:00 PM</li>
                            <li class="mb-2">
                                <i class="fas fa-info me-2"></i>
                                <?= $loc['status'] === 'open' ? '<span style="color:#059669;font-weight:600;">Open</span>' : '<span style="color:#b45309;font-weight:600;">Closed</span>' ?>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>


    <div id="paymentMethodModal" class="payment-modal" aria-hidden="true">
        <div class="payment-modal-backdrop" data-close="backdrop"></div>
        <div class="payment-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="paymentModalTitle">
            <button class="payment-modal-close" type="button" aria-label="Close">&times;</button>
            <h3 id="paymentModalTitle" style="margin-top:0;color:#2d4a3a;">Confirm payment</h3>
            <div class="payment-modal-actions" style="display:flex;gap:12px;margin-top:14px;">
                <button id="payCashBtn" class="auth-btn" style="flex:1;padding:12px 18px;"
                    onclick="handlePaymentChoice('cash')">Place Order (Cash)</button>
            </div>
            <!-- Removed GCash button / QR preview -->
        </div>
    </div>



    <!-- Product Detail Modal -->
    <div id="productModal" class="product-modal">
        <button class="product-modal-close-yellow" onclick="closeProductModal()" aria-label="Close">
            &times;
        </button>

        <!-- Main Content -->
        <div class="product-modal-content">
            <div class="product-modal-grid">

                <div class="product-modal-image">
                    <img id="modalProductImage" src="/placeholder.svg" alt="">
                </div>
                <div class="product-modal-details">
                    <h1 id="modalProductName" class="product-modal-title"></h1>
                    <p id="modalProductPrice" class="product-modal-price"></p>

                    <div class="product-modal-description">
                        <h3>Product Description</h3>
                        <p id="modalProductDescription"></p>
                    </div>
                    <div class="product-modal-sizes">
                        <h3>Size</h3>
                        <div class="size-buttons">
                            <button class="size-btn active" onclick="selectSize('Grande')">Grande</button>
                            <button class="size-btn" onclick="selectSize('Supreme')">Supreme</button>
                        </div>
                    </div>

                    <div class="product-modal-sugar">
                        <h3>Sugar</h3>
                        <div class="sugar-buttons">
                            <button type="button" class="sugar-btn active" data-sugar="Less Sweet">Less Sweet</button>
                            <button type="button" class="sugar-btn" data-sugar="More Sweet">More Sweet</button>
                        </div>
                    </div>


                    <!-- Toppings choices -->
                    <div class="product-modal-toppings" style="margin-top:12px;">
                        <h3>Add-ons / Toppings</h3>

                        <?php if (!empty($_SESSION['user']['is_admin'])): ?>
                            <div style="margin:8px 0;">
                                <button id="showAddToppingModalBtn" class="btn" style="background:#059669;color:#fff;padding:6px 10px;border-radius:8px;font-weight:600;">
                                    + Add Topping
                                </button>
                            </div>
                        <?php endif; ?>

                        <!-- Replace static buttons with a placeholder container -->
                        <div id="toppingsList" class="add-ons-grid" style="margin-top:10px;">
                            <div style="padding:8px 6px;font-size:.9rem;color:#6b7280;">Loading toppings...</div>
                        </div>
                    </div>

                    <div style="height:12px"></div>

                    <div style="display:flex;gap:10px;align-items:center;">
                        <div style="font-weight:700;" id="modalTotalLabel">Total: ₱<span id="modalTotalAmount">0.00</span></div>
                        <button class="product-modal-add-cart" onclick="addProductToCart()">
                            Add to Cart
                        </button>
                    </div>

                </div>
            </div>
        </div>
    </div>
    </div>




    <!-- Cart Icon -->
    <button class="cart-icon" onclick="openCart()">
        <i class="fas fa-shopping-cart"></i>
        <span class="cart-badge" id="cartCount">0</span>
    </button>


    <!-- Cart Modal -->
    <div id="cartModal" class="cart-modal">
        <div class="cart-content">
            <div class="cart-header">
                <h3>Your Cart</h3>
                <button class="close-cart" onclick="closeCart()">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <div id="cartItems" class="cart-items">
            </div>

            <div id="deliveryOptions" class="delivery-options" style="display: none;">
                <h4>Pickup Details</h4>
                <div class="form-group">
                    <label for="pickupName">Name for Pickup</label>
                    <input type="text" id="pickupName" placeholder="Enter your name" required>
                </div>
                <div class="form-group">
                    <label for="pickupLocation">Pickup Location</label>
                    <select id="pickupLocation" required>
                        <?php
                        require_once __DIR__ . '/admin/database/db_connect.php';
                        $pickupLocations = [];
                        try {
                            $db = new Database();
                            $pdo_conn = $db->opencon();
                            $stmt = $pdo_conn->prepare("SELECT name FROM locations WHERE status = 'open'");
                            $stmt->execute();
                            $pickupLocations = $stmt->fetchAll(PDO::FETCH_COLUMN);
                        } catch (PDOException $e) {
                            error_log('DB error fetching pickup locations: ' . $e->getMessage());
                            $pickupLocations = [];
                        }
                        foreach ($pickupLocations as $loc) {
                            echo '<option value="' . htmlspecialchars($loc) . '">' . htmlspecialchars($loc) . '</option>';
                        }
                        ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="pickupTime">Pickup Time</label>
                    <input type="time" id="pickupTime" required min="15:00" max="20:30">
                    <p id="pickupTimeNote" style="margin-top:6px;font-size:0.95em;color:#b45309;">
                        <strong>Note:</strong> Shop is open for pickup only from 3:00 p.m. to 8:30 p.m.
                    </p>
                </div>
                <div class="form-group">
                    <label for="specialInstructions">Special Instructions (Optional)</label>
                    <textarea id="specialInstructions" rows="2" placeholder="Any special delivery instructions..."></textarea>
                </div>
            </div>

            <div id="cartTotal" class="cart-total">
                <div class="total-container">
                    <div id="totalAmount" class="total-amount">Total: $0.00</div>
                    <button class="checkout-btn">Checkout</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ORDER ONLINE -->
    <section class="food-order-section py-5 text-center" style="background-color:#f3ebd3; color: #2d4a3a; border-radius: 20px; margin: 20px;">
        <div class="container">
            <div class="plain-circle-icon mb-4 mx-auto" style="background-color: #2d4a3a;">
                <i class="fas fa-truck" style="color: #f3ebd3; font-size: 2rem; padding: 10px;"></i>
            </div>
            <h2 class="order-title fw-bold mb-2">Inquire now!</h2>
            <p class="order-subtitle lead mb-4">Be part of our team</p>

            <div class="d-flex flex-wrap justify-content-center gap-3 style">
                <a href="https://www.facebook.com/cupsandcuddles" class="btn order-btn-custom" style="background-color: #2d4a3a;" target="_blank" rel="noopener noreferrer">
                    <i class="fas fa-truck me-2"></i> Message Us
                </a>
            </div>
        </div>
    </section>


    <footer class="main-footer">
        <div class="footer-container">
            <div class="footer-content-grid">
                <div class="footer-brand">
                    <div class="footer-logo-slogan" style="display: flex; align-items: center; gap: 12px; margin-bottom: 12px;">
                        <div class="footer-logo-icon">
                            <i class="fas fa-mug-hot"></i>
                        </div>
                        <h3 class="footer-slogan-text" style="margin:0;">Feeling and<br>Experience</h3>
                    </div>
                    <div class="footer-contact">
                        <div class="footer-contact-item">
                            <i class="fas fa-mobile-alt" style="margin-right:8px;"></i>
                            <span>+63 926 429 6136</span>
                        </div>
                        <div class="footer-contact-item">
                            <i class="fas fa-envelope" style="margin-right:8px;"></i>
                            <span>cupsandcuddlesph@gmail.com</span>
                        </div>
                    </div>
                </div>
                <div class="footer-deliver">
                    <h4> ORDER ONLINE</h4>
                    <div class="social-icons">

                        <a href="https://www.facebook.com/alaehxpressdeliverymain" class="social-icon" target="_blank" rel="noopener noreferrer">
                            <i class="fas fa-truck"></i>
                        </a>

                    </div>
                </div>
                <div class="footer-social">
                    <h4>JOIN US ON</h4>
                    <div class="social-icons">
                        <a href="https://www.instagram.com/cupsandcuddles.ph" class="social-icon" target="_blank" rel="noopener noreferrer">
                            <i class="fab fa-instagram"></i>
                        </a>

                        <a href="https://www.facebook.com/cupsandcuddles" class="social-icon" target="_blank" rel="noopener noreferrer">
                            <i class="fab fa-facebook-f"></i>
                        </a>

                    </div>
                </div>
            </div>
            <div class="footer-slogan">
                <h1>CUPS</h1>
                <h3>&</h3>
                <h2>CUDDLES</h2>
            </div>
        </div>
    </footer>



    <script>
        window.PHP_IS_LOGGED_IN = <?php echo $isLoggedIn ? 'true' : 'false'; ?>;
        window.PHP_USER_FN = "<?php echo addslashes($_SESSION['user']['user_FN'] ?? ''); ?>";
        window.PHP_USER_LN = "<?php echo addslashes($_SESSION['user']['user_LN'] ?? ''); ?>";
        window.PHP_USER_EMAIL = "<?php echo addslashes($_SESSION['user']['user_email'] ?? ''); ?>";
        window.PHP_USER_IMAGE = "<?php echo isset($_SESSION['user']['profile_image']) ? addslashes($_SESSION['user']['profile_image']) : 'img/default-avatar.png'; ?>";
    </script>
    <script>
        // expose super-admin flag to admin UI JS (used to show force-delete)
        window.IS_SUPER_ADMIN = <?php echo Database::isSuperAdmin() ? 'true' : 'false'; ?>;
    </script>
    <script src="js/script.js"></script>
    <script src="js/receipt.js"></script>
    <script>
        document.addEventListener("DOMContentLoaded", () => {
            const pickupTimeInput = document.getElementById("pickupTime");
            const note = document.getElementById("pickupTimeNote");

            if (pickupTimeInput && note) {
                pickupTimeInput.addEventListener("input", function() {
                    const val = this.value;
                    if (!val) {
                        note.textContent = "Note: Shop is open for pickup only from 3:00 p.m. to 8:30 p.m.";
                        note.style.color = "#b45309";
                        this.setCustomValidity("");
                        return;
                    }

                    const [hour, minute] = val.split(":").map(Number);
                    const totalMins = hour * 60 + minute;

                    const openMins = 15 * 60; // 3:00 PM
                    const closeMins = 20 * 60 + 30; // 8:30 PM

                    if (totalMins < openMins || totalMins > closeMins) {
                        note.textContent = "❌ Please select a time between 3:00 p.m. and 8:30 p.m.";
                        note.style.color = "#dc2626";
                        this.setCustomValidity("Invalid time selected.");
                    } else {
                        note.textContent = "✅ Valid time.";
                        note.style.color = "#22a06b";
                        this.setCustomValidity("");
                    }
                });
            }
        });






        // ---------------- VALIDATION ----------------
(function() {
    const fn = document.getElementById('registerName');
    const ln = document.getElementById('registerLastName');
    const em = document.getElementById('registerEmail');
    const pw = document.getElementById('registerPassword');
    const cpw = document.getElementById('confirmPassword');
    const btn = document.getElementById('registerBtn');

    const errFN = document.getElementById('firstnameError');
    const errLN = document.getElementById('lastnameError');
    const errEM = document.getElementById('emailError');
    const errPW = document.getElementById('passwordError');
    const errCPW = document.getElementById('confirmPasswordError');

    const state = { fn: false, ln: false, em: false, pw: false, cpw: false };
    let emailTimer = null;

    function setValid(el, ok) {
        el.classList.toggle('is-valid', !!ok);
        el.classList.toggle('is-invalid', !ok);
    }

    function nameRules(val) {
        if (!val) return 'Required';
        if (val.length < 2) return 'Too short';
        if (!/^[a-zA-Z\s'.-]+$/.test(val)) return 'Invalid characters';
        return '';
    }

    function validateFN() {
        const msg = nameRules(fn.value.trim());
        errFN.textContent = msg;
        setValid(fn, !msg);
        state.fn = !msg;
        updateButton();
    }
    function validateLN() {
        const msg = nameRules(ln.value.trim());
        errLN.textContent = msg;
        setValid(ln, !msg);
        state.ln = !msg;
        updateButton();
    }

    function validateEmailFormat(v) { return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(v); }
    function checkEmail() {
        const v = em.value.trim();
        if (!v) { errEM.textContent = 'Required'; setValid(em, false); state.em = false; updateButton(); return; }
        if (!validateEmailFormat(v)) { errEM.textContent = 'Invalid format'; setValid(em, false); state.em = false; updateButton(); return; }

        errEM.textContent = 'Checking...';
        setValid(em, true);
        if (emailTimer) clearTimeout(emailTimer);
        emailTimer = setTimeout(() => {
            fetch('AJAX/check_duplicates.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ field: 'user_email', value: v })
            })
            .then(r => r.json())
            .then(data => {
                if (data.exists) { errEM.textContent = 'Email already registered'; setValid(em, false); state.em = false; }
                else { errEM.textContent = ''; setValid(em, true); state.em = true; }
                updateButton();
            })
            .catch(() => { errEM.textContent = 'Server error'; setValid(em, false); state.em = false; updateButton(); });
        }, 400);
    }

    function validatePW() {
        const v = pw.value;
        if (!v) { errPW.textContent = 'Required'; setValid(pw, false); state.pw = false; updateButton(); return; }
        if (v.length < 8) { errPW.textContent = 'At least 8 characters'; setValid(pw, false); state.pw = false; updateButton(); return; }
        if (!/[A-Z]/.test(v) || !/[0-9]/.test(v) || !/[^A-Za-z0-9]/.test(v)) {
            errPW.textContent = 'Must contain uppercase, number & special character';
            setValid(pw, false); state.pw = false;
        } else {
            errPW.textContent = '';
            setValid(pw, true); state.pw = true;
        }
        validateCPW();
        updateButton();
    }

    function validateCPW() {
        if (!cpw.value) { errCPW.textContent = 'Required'; setValid(cpw, false); state.cpw = false; }
        else if (cpw.value !== pw.value) { errCPW.textContent = 'Passwords do not match'; setValid(cpw, false); state.cpw = false; }
        else { errCPW.textContent = ''; setValid(cpw, true); state.cpw = true; }
        updateButton();
    }

    function updateButton() { btn.disabled = !Object.values(state).every(Boolean); }

    fn.addEventListener('input', validateFN);
    ln.addEventListener('input', validateLN);
    em.addEventListener('input', checkEmail);
    pw.addEventListener('input', validatePW);
    cpw.addEventListener('input', validateCPW);
    cpw.addEventListener('blur', validateCPW);
    btn.disabled = true;
})();

// ---------------- PASSWORD TOGGLE ----------------
(function() {
    document.querySelectorAll('.password-toggle-btn').forEach(btn => {
        const targetId = btn.getAttribute('data-target');
        const input = document.getElementById(targetId);
        if (!input) return;

        function syncState() {
            if (input.type === 'password') {
                btn.innerHTML = '<i class="fas fa-eye"></i>';
            } else {
                btn.innerHTML = '<i class="fas fa-eye-slash"></i>';
            }
            btn.style.display = input.value.length > 0 ? 'block' : 'none';
        }

        btn.addEventListener('click', () => {
            input.type = input.type === 'password' ? 'text' : 'password';
            syncState();
            input.focus();
        });

        input.addEventListener('input', syncState);
        input.addEventListener('focus', syncState);
        input.addEventListener('blur', syncState);
        syncState();
    });
})();

// ---------------- INFINITE PROMO CAROUSEL ----------------
(function initInfiniteCarousel(){
    const container = document.querySelector('.impact-stories');
    const track = container && container.querySelector('.carousel-track');
    if (!track) return;

    // If already initialized, skip
    if (track.dataset.infinite === '1') return;

    const pxPerSec = 0.2; // very slow speed in pixels per second

    // Ensure the first set is at least as wide as the container; then duplicate it once
    // to create a seamless loop where animation translates by -50%.
    const origItems = Array.from(track.children);
    if (!origItems.length) return;

    // Grow the base set to be at least as wide as the container
    let safety = 0;
    while (track.scrollWidth < (container.clientWidth || window.innerWidth)) {
        // Append a copy of the original set until we fill container width
        for (const node of origItems) {
            const clone = node.cloneNode(true);
            clone.setAttribute('aria-hidden', 'true');
            clone.dataset.basefill = '1';
            track.appendChild(clone);
        }
        if (++safety > 6) break; // avoid runaway if styles change
    }

    // Take a snapshot of the current first "set" width
    const baseNodes = Array.from(track.children);
    const baseWidth = track.scrollWidth; // current total width

    // Duplicate the entire set once more to enable seamless -50% translate loop
    for (const node of baseNodes) {
        const clone = node.cloneNode(true);
        clone.setAttribute('aria-hidden', 'true');
        clone.dataset.clone = '1';
        track.appendChild(clone);
    }

    // After duplication, total width is 2 * baseWidth; move by -50% equals baseWidth.
    function applyDuration() {
        const total = track.scrollWidth; // 2 * baseWidth ideally
        const half = Math.max(1, Math.round(total / 2));
        // Ensure at least 60s per half-loop for user-friendly speed
        const duration = Math.max(60, Math.round(half / pxPerSec));
        // Apply both the CSS var and inline duration to override any conflicting rules
        track.style.setProperty('--marquee-duration', duration + 's');
        track.style.animationDuration = duration + 's';
    }
    applyDuration();
    track.dataset.infinite = '1';

    // Pause on hover (CSS already handles .carousel-track:hover), but also support touch
    track.addEventListener('touchstart', () => { track.style.animationPlayState = 'paused'; }, { passive: true });
    track.addEventListener('touchend', () => { track.style.animationPlayState = ''; });

    // Recalculate duration on resize to avoid any timing mismatch or perceived gaps
    let _rz;
    window.addEventListener('resize', () => {
        clearTimeout(_rz);
        _rz = setTimeout(applyDuration, 150);
    });
})();

    </script>
</body>

</html>

<!-- Edit Profile Modal -->
<div id="editProfileModal" class="auth-modal">
    <div class="auth-content">
        <button class="close-auth" onclick="closeEditProfileModal()">
            <i class="fas fa-times"></i>
        </button>
        <div class="auth-header">
            <h3>Edit Profile</h3>
            <p>Update your account information</p>
        </div>
        <form id="editProfileForm" class="auth-form" onsubmit="handleEditProfile(event); return false;">
            <div class="form-group">
                <label for="editProfileFN">First Name</label>
                <input type="text" id="editProfileFN" name="user_FN" required />
            </div>
            <div class="form-group">
                <label for="editProfileLN">Last Name</label>
                <input type="text" id="editProfileLN" name="user_LN" required />
            </div>
            <div class="form-group">
                <label for="editProfileEmail">Email</label>
                <input type="email" id="editProfileEmail" name="user_email" required />
            </div>
            <div class="form-group">
                <label for="editProfilePassword">New Password <span style="font-weight:400;font-size:0.95em;">(leave blank to keep current)</span></label>
                <input type="password" id="editProfilePassword" name="user_password" minlength="8" autocomplete="new-password" />
            </div>
            <button type="submit" class="auth-btn" id="editProfileBtn">
                <i class="fas fa-save"></i> Save Changes
            </button>
        </form>
        <div id="editProfileSuccess" class="success-message" style="display:none;"></div>
        <div id="editProfileError" class="error-message" style="display:none;color:#dc2626;margin-top:10px;"></div>
    </div>
</div>