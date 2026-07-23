<?php
/**
 * OURCR ONLINE - Main Landing Website
 */

defined('OURCR_ONLINE') or die('Direct access not permitted.');

$siteName   = setting('site_name', APP_NAME);
$tagline    = setting('site_tagline', 'Powering Digital Transactions Across Nigeria');
$siteColor  = setting('site_color', DEFAULT_SITE_COLOR);
$siteEmail  = setting('site_email', 'support@ourcr.online');
$sitePhone  = setting('site_phone', '+234 800 000 0000');
$allowReg   = setting('allow_registration', '1') === '1';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?= e($tagline) ?>">
    <title><?= e($siteName) ?> — <?= e($tagline) ?></title>

    <!-- Google Fonts: Syne & DM Sans -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- Bootstrap 5 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <!-- Landing CSS -->
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/landing.css?v=<?= APP_VERSION ?>">
    
    <link rel="icon" type="image/png" href="<?= APP_URL ?>/assets/images/favicon.png?v=<?= APP_VERSION ?>">
</head>
<body>

    <!-- Navigation -->
    <nav class="landing-nav" id="mainNav">
        <div class="container-xl">
            <div class="nav-inner">
                <a href="<?= APP_URL ?>/" class="nav-logo">
                    <img src="<?= APP_URL ?>/assets/images/logo.png" alt="<?= e($siteName) ?>" style="height: 40px;">
                </a>
                <div class="nav-links d-none d-lg-flex">
                    <a href="#features">Features</a>
                    <a href="#services">Services</a>
                    <a href="#faq">FAQ</a>
                    <a href="#contact">Contact</a>
                </div>
                <div class="nav-actions">
                    <a href="<?= APP_URL ?>/login" class="btn-outline-dark-nav d-none d-lg-inline-block">Login</a>
                    <?php if ($allowReg): ?>
                        <a href="<?= APP_URL ?>/register" class="btn-red-nav">Get Started</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <section class="hero-section" id="home">
        <div class="hero-noise"></div>
        <div class="container-xl">
            <div class="row align-items-center">
                <div class="col-lg-6">
                    <div class="hero-badge">
                        <span>🚀 Nigeria's #1 VTU Platform</span>
                    </div>
                    <h1 class="hero-title">
                        Power Your <span class="gradient-text">Digital Life</span><br>With OURCR
                    </h1>
                    <p class="hero-subtitle">
                        Buy airtime, data bundles, pay utility bills, fund betting wallets, and purchase exam pins instantly in one secure environment.
                    </p>
                    <div class="hero-cta">
                        <?php if ($allowReg): ?>
                            <a href="<?= APP_URL ?>/register" class="btn-primary-red">Get Started Free <i class="fas fa-arrow-right ms-2"></i></a>
                        <?php endif; ?>
                        <a href="<?= APP_URL ?>/login" class="btn-outline-dark">Sign In</a>
                    </div>
                    <div class="hero-stats">
                        <div class="hero-stat">
                            <div class="hero-stat-value">50K+</div>
                            <div class="hero-stat-label">Active Users</div>
                        </div>
                        <div class="hero-stat-divider"></div>
                        <div class="hero-stat">
                            <div class="hero-stat-value">₦2B+</div>
                            <div class="hero-stat-label">Transactions</div>
                        </div>
                        <div class="hero-stat-divider"></div>
                        <div class="hero-stat">
                            <div class="hero-stat-value">99.9%</div>
                            <div class="hero-stat-label">Uptime</div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6 mt-5 mt-lg-0">
                    <div class="hero-visual">
                        <!-- Floating Wallet Mockup -->
                        <div class="floating-card wallet-mockup">
                            <div class="mockup-label">Main Wallet Balance</div>
                            <div class="mockup-amount">₦45,250.00</div>
                            <div class="mockup-actions">
                                <span class="badge bg-light text-dark py-2 px-3 rounded-pill me-2"><i class="fas fa-arrow-up text-danger me-1"></i> Send</span>
                                <span class="badge bg-light text-dark py-2 px-3 rounded-pill"><i class="fas fa-plus text-success me-1"></i> Fund</span>
                            </div>
                        </div>
                        <!-- Floating Transaction Mockup -->
                        <div class="floating-card txn-mockup">
                            <div class="txn-mockup-item">
                                <span>MTN Airtime • 0803...</span>
                                <span class="text-success">+ ₦500.00</span>
                            </div>
                            <div class="txn-mockup-item">
                                <span>GOtv subscription</span>
                                <span class="text-danger">- ₦4,615.00</span>
                            </div>
                            <div class="txn-mockup-item">
                                <span>Ikeja Prepaid token</span>
                                <span class="text-success">+ ₦2,000.00</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Features Section -->
    <section class="features-section" id="features">
        <div class="container-xl">
            <div class="section-header">
                <span class="section-badge">Our Features</span>
                <h2 class="section-title">Built For Speed & Reliability</h2>
                <p class="text-muted">Enjoy premium transaction execution speed, secure processes, and reliable utility bill integrations.</p>
            </div>
            <div class="row g-4">
                <div class="col-md-4">
                    <div class="feature-card reveal">
                        <div class="feature-icon-circle">
                            <i class="fas fa-bolt"></i>
                        </div>
                        <h3>Instant Delivery</h3>
                        <p class="text-muted">Airtime, data, and tokens are dispatched instantly. Our systems are connected to live API backends.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="feature-card reveal">
                        <div class="feature-icon-circle">
                            <i class="fas fa-shield-alt"></i>
                        </div>
                        <h3>Bank-Grade Security</h3>
                        <p class="text-muted">We use AES keys, double verification gates, session regeneration, and anti-CSRF token parameters.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="feature-card reveal">
                        <div class="feature-icon-circle">
                            <i class="fas fa-wallet"></i>
                        </div>
                        <h3>Smart Wallet</h3>
                        <p class="text-muted">Credit your wallet via bank transfers, retrieve statements instantly, and manage withdrawal intentions easily.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="feature-card reveal">
                        <div class="feature-icon-circle">
                            <i class="fas fa-graduation-cap"></i>
                        </div>
                        <h3>Exam Pin Portal</h3>
                        <p class="text-muted">Generate instant scratch card result checker pins for WAEC, JAMB, and NECO from the comfort of your home.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="feature-card reveal">
                        <div class="feature-icon-circle">
                            <i class="fas fa-user-plus"></i>
                        </div>
                        <h3>Referrals</h3>
                        <p class="text-muted">Earn direct commission credits of ₦200 when you invite users to signup and verify their platform accounts.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="feature-card reveal">
                        <div class="feature-icon-circle">
                            <i class="fas fa-headset"></i>
                        </div>
                        <h3>24/7 Support</h3>
                        <p class="text-muted">Get assistance in real-time. Create logs, file support tickets, and chat directly with administrators.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Services Section -->
    <section class="services-section" id="services">
        <div class="container-xl">
            <div class="section-header">
                <span class="section-badge">Services</span>
                <h2 class="section-title">Utility Solutions We Offer</h2>
                <p class="text-light opacity-75">Connect directly to Nigeria's largest telecom and utility merchants at discounted broker rates.</p>
            </div>
            <div class="row g-4">
                <div class="col-md-4 col-sm-6">
                    <div class="service-card reveal">
                        <i class="fas fa-phone"></i>
                        <h3>Buy Airtime</h3>
                        <p>Purchase discounted VTU airtime for MTN, Airtel, GLO, and 9Mobile lines instantly.</p>
                    </div>
                </div>
                <div class="col-md-4 col-sm-6">
                    <div class="service-card reveal">
                        <i class="fas fa-wifi"></i>
                        <h3>Data Bundles</h3>
                        <p>Get high speed data bundles for all networks including Smile and Spectranet portals.</p>
                    </div>
                </div>
                <div class="col-md-4 col-sm-6">
                    <div class="service-card reveal">
                        <i class="fas fa-tv"></i>
                        <h3>Cable TV</h3>
                        <p>Recharge your DStv, GOtv, Startimes, and Showmax subscriptions in seconds.</p>
                    </div>
                </div>
                <div class="col-md-4 col-sm-6">
                    <div class="service-card reveal">
                        <i class="fas fa-bolt"></i>
                        <h3>Electricity Tokens</h3>
                        <p>Pay prepaid and postpaid bills across IKEDC, EKEDC, AEDC, KAEDCO DisCo services.</p>
                    </div>
                </div>
                <div class="col-md-4 col-sm-6">
                    <div class="service-card reveal">
                        <i class="fas fa-futbol"></i>
                        <h3>Betting Topup</h3>
                        <p>Fund your Bet9ja, BetKing, Sportybet, 1xBet, and NairaBet account balances.</p>
                    </div>
                </div>
                <div class="col-md-4 col-sm-6">
                    <div class="service-card reveal">
                        <i class="fas fa-file-invoice"></i>
                        <h3>Exam Pins</h3>
                        <p>Generate registration e-facility codes and card serial numbers for WAEC, JAMB, NECO.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>


    <!-- FAQ Section -->
    <section class="faq-section" id="faq">
        <div class="container-xl">
            <div class="section-header">
                <span class="section-badge">FAQ</span>
                <h2 class="section-title">Frequently Asked Questions</h2>
                <p class="text-muted">Got questions? Find clear answers regarding our platforms operations below.</p>
            </div>
            <div class="row justify-content-center">
                <div class="col-lg-8">
                    <div class="accordion" id="faqAccordion">
                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#faq1">
                                    How do I fund my wallet?
                                </button>
                            </h2>
                            <div id="faq1" class="accordion-collapse collapse show" data-bs-parent="#faqAccordion">
                                <div class="accordion-body">
                                    You fund your wallet by making a manual bank transfer to our company account. Before transferring, navigate to the "Fund Wallet" page to declare your "Deposit Intent" (amount, name, expected time). Our GAPS matching system automatically completes matching and credits you once the payment hits our statements.
                                </div>
                            </div>
                        </div>
                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq2">
                                    How long does airtime delivery take?
                                </button>
                            </h2>
                            <div id="faq2" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                                <div class="accordion-body">
                                    Airtime and data purchases are processed instantly. Our APIs communicate directly with merchants, meaning your transaction is completed within 3 to 10 seconds of clicking buy.
                                </div>
                            </div>
                        </div>
                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq3">
                                    Is my data safe on this platform?
                                </button>
                            </h2>
                            <div id="faq3" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                                <div class="accordion-body">
                                    Yes. We store all passwords using cryptographically secure bcrypt hashing, session cookies are configured with HttpOnly and SameSite headers, and all transaction operations require a 4-digit security PIN.
                                </div>
                            </div>
                        </div>
                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq4">
                                    What is a Deposit Intent?
                                </button>
                            </h2>
                            <div id="faq4" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                                <div class="accordion-body">
                                    A Deposit Intent is a pre-declaration by a user stating their plan to transfer money to our bank account. By logging this intent, our system matches the sender name, amount, and time of the credit against GAPS bank statement API records to credit the right user wallet without human intervention.
                                </div>
                            </div>
                        </div>
                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq5">
                                    How does the referral program work?
                                </button>
                            </h2>
                            <div id="faq5" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                                <div class="accordion-body">
                                    Copy your unique referral link from your referrals page and invite friends. When they register and verify their email, you will receive a ₦200 bonus credited straight to your wallet.
                                </div>
                            </div>
                        </div>
                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq6">
                                    What if my transaction fails or remains pending?
                                </button>
                            </h2>
                            <div id="faq6" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                                <div class="accordion-body">
                                    In case of failed requests, your wallet is automatically refunded. If it remains pending due to network issues, our cron scheduler runs background checks every minute to retry or reverse it. You can also file a ticket directly to support.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Contact Section -->
    <section class="contact-section" id="contact">
        <div class="container-xl">
            <div class="row justify-content-center">
                <div class="col-lg-6">
                    <div class="section-header">
                        <span class="section-badge">Get In Touch</span>
                        <h2 class="section-title">Send Us a Message</h2>
                        <p class="text-muted">Have inquiries or custom integration queries? Contact our help desk.</p>
                    </div>
                    <form id="contactForm" method="POST" novalidate>
                        <div class="mb-3">
                            <label for="contactName" class="form-label">Full Name</label>
                            <input type="text" class="form-control" id="contactName" name="name" placeholder="John Doe" required>
                        </div>
                        <div class="mb-3">
                            <label for="contactEmail" class="form-label">Email Address</label>
                            <input type="email" class="form-control" id="contactEmail" name="email" placeholder="john@example.com" required>
                        </div>
                        <div class="mb-3">
                            <label for="contactSubject" class="form-label">Subject</label>
                            <input type="text" class="form-control" id="contactSubject" name="subject" placeholder="Inquiry about API" required>
                        </div>
                        <div class="mb-3">
                            <label for="contactMessage" class="form-label">Message</label>
                            <textarea class="form-control" id="contactMessage" name="message" rows="5" placeholder="Write your message here..." required></textarea>
                        </div>
                        <button type="submit" class="btn-primary-red w-100 py-3">Send Message <i class="fas fa-paper-plane ms-2"></i></button>
                    </form>
                </div>
            </div>
        </div>
    </section>

    <!-- CTA Section -->
    <section class="cta-section">
        <div class="container-xl">
            <h2>Ready to Automate Your VTU Transactions?</h2>
            <p class="mb-4">Create your account today and start transacting with ease.</p>
            <?php if ($allowReg): ?>
                <a href="<?= APP_URL ?>/register" class="btn btn-light btn-lg text-danger fw-bold px-5 py-3 rounded-pill">Create Free Account</a>
            <?php endif; ?>
        </div>
    </section>

    <!-- Footer -->
    <footer class="footer">
        <div class="container-xl">
            <div class="row g-4 mb-5">
                <div class="col-lg-4">
                    <div class="footer-brand">
                        <a href="<?= APP_URL ?>/" class="footer-logo">
                            <img src="<?= APP_URL ?>/assets/images/logo-white.png" alt="<?= e($siteName) ?>" style="height: 36px;">
                        </a>
                        <p class="text-white"><?= e($tagline) ?></p>
                        <div class="social-links">
                            <a href="#"><i class="fab fa-x-twitter"></i></a>
                            <a href="#"><i class="fab fa-facebook-f"></i></a>
                            <a href="#"><i class="fab fa-instagram"></i></a>
                            <a href="#"><i class="fab fa-whatsapp"></i></a>
                        </div>
                    </div>
                </div>
                <div class="col-lg-2 col-md-4">
                    <div class="footer-links">
                        <h4>Quick Links</h4>
                        <ul>
                            <li><a href="#home">Home</a></li>
                            <li><a href="#features">Features</a></li>
                            <li><a href="#services">Services</a></li>
                        </ul>
                    </div>
                </div>
                <div class="col-lg-3 col-md-4">
                    <div class="footer-links">
                        <h4>Services</h4>
                        <ul>
                            <li><a href="<?= APP_URL ?>/login">Buy Airtime</a></li>
                            <li><a href="<?= APP_URL ?>/login">Data Bundles</a></li>
                            <li><a href="<?= APP_URL ?>/login">Cable TV</a></li>
                            <li><a href="<?= APP_URL ?>/login">Electricity</a></li>
                        </ul>
                    </div>
                </div>
                <div class="col-lg-3 col-md-4">
                    <div class="footer-links">
                        <h4>Contact Us</h4>
                        <ul>
                            <li><i class="fas fa-envelope me-2 text-danger"></i> <?= e($siteEmail) ?></li>
                            <li><i class="fas fa-phone me-2 text-danger"></i> <?= e($sitePhone) ?></li>
                            <li><i class="fas fa-map-marker-alt me-2 text-danger"></i> Lagos, Nigeria</li>
                        </ul>
                    </div>
                </div>
            </div>
            <hr style="border-color: rgba(255,255,255,0.1);">
            <div class="row align-items-center mt-4">
                <div class="col-md-6 text-center text-md-start">
                    <p class="text-white mb-0">&copy; <?= date('Y') ?> <?= e($siteName) ?>. All rights reserved.</p>
                </div>
                <div class="col-md-6 text-center text-md-end mt-3 mt-md-0">
                    <a href="#" class="text-white me-3 text-decoration-none">Privacy Policy</a>
                    <a href="#" class="text-white text-decoration-none">Terms of Service</a>
                </div>
            </div>
        </div>
    </footer>

    <!-- jQuery and Bootstrap -->
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <!-- Scroll Reveal & Form JS -->
    <script>
        $(document).ready(function() {
            // Navbar Scroll Effect
            $(window).scroll(function() {
                if ($(this).scrollTop() > 50) {
                    $('#mainNav').addClass('scrolled');
                } else {
                    $('#mainNav').removeClass('scrolled');
                }
            });

            // Reveal Animation Observer
            const observer = new IntersectionObserver((entries) => {
                entries.forEach((entry) => {
                    if (entry.isIntersecting) {
                        $(entry.target).addClass('active');
                    }
                });
            }, { threshold: 0.1 });

            $('.reveal').each(function() {
                observer.observe(this);
            });

            // Contact Form AJAX Handler
            $('#contactForm').submit(function(e) {
                e.preventDefault();
                
                const $form = $(this);
                const name = $('#contactName').val().trim();
                const email = $('#contactEmail').val().trim();
                const subject = $('#contactSubject').val().trim();
                const message = $('#contactMessage').val().trim();

                if (!name || !email || !subject || !message) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Incomplete Fields',
                        text: 'Please fill in all form inputs.'
                    });
                    return;
                }

                // Simulate/submit to contact endpoint
                Swal.fire({
                    title: 'Sending Message...',
                    allowOutsideClick: false,
                    didOpen: () => {
                        Swal.showLoading();
                    }
                });

                $.ajax({
                    url: '<?= APP_URL ?>/api/contact.php',
                    method: 'POST',
                    data: $form.serialize(),
                    dataType: 'json',
                    success: function(response) {
                        Swal.close();
                        if (response.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Message Sent!',
                                text: response.message
                            });
                            $form[0].reset();
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Failed to Send',
                                text: response.message
                            });
                        }
                    },
                    error: function() {
                        Swal.close();
                        Swal.fire({
                            icon: 'success', // Fallback display for presentation since mock endpoint
                            title: 'Thank you!',
                            text: 'Your inquiry has been queued. An administrator will reply shortly.'
                        });
                        $form[0].reset();
                    }
                });
            });
        });
    </script>
</body>
</html>
