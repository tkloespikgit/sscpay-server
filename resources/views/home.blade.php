<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>OmniOrder · Multi‑Merchant Order Management</title>
    <!-- Google Fonts + Font Awesome -->
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" />
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background-color: #fafbfc;
            color: #0b1a2f;
            line-height: 1.5;
        }

        .container {
            max-width: 1280px;
            margin: 0 auto;
            padding: 0 32px;
        }

        /* ---- HEADER / NAV ---- */
        .navbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 20px 0;
            border-bottom: 1px solid rgba(11, 26, 47, 0.05);
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 700;
            font-size: 1.6rem;
            letter-spacing: -0.02em;
            color: #0b1a2f;
        }
        .logo i {
            color: #2563eb;
            font-size: 1.8rem;
        }
        .logo span {
            color: #2563eb;
        }

        .nav-links {
            display: flex;
            align-items: center;
            gap: 36px;
            list-style: none;
            font-weight: 500;
            font-size: 0.95rem;
        }
        .nav-links a {
            text-decoration: none;
            color: #1e293b;
            transition: color 0.2s;
        }
        .nav-links a:hover {
            color: #2563eb;
        }

        .nav-actions {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        .btn-outline {
            padding: 10px 22px;
            border: 1.5px solid #d1d9e6;
            border-radius: 40px;
            background: transparent;
            font-weight: 600;
            font-size: 0.9rem;
            color: #1e293b;
            cursor: pointer;
            transition: 0.2s;
        }
        .btn-outline:hover {
            border-color: #2563eb;
            color: #2563eb;
        }
        .btn-primary {
            padding: 10px 28px;
            border: none;
            border-radius: 40px;
            background: #0b1a2f;
            color: white;
            font-weight: 600;
            font-size: 0.9rem;
            cursor: pointer;
            transition: 0.2s;
            box-shadow: 0 4px 12px rgba(11, 26, 47, 0.12);
        }
        .btn-primary:hover {
            background: #1a2f4a;
            transform: translateY(-2px);
        }
        .btn-primary i {
            margin-right: 8px;
        }

        /* ---- HERO ---- */
        .hero {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 64px 0 72px;
            gap: 60px;
            flex-wrap: wrap;
        }
        .hero-text {
            flex: 1 1 500px;
        }
        .hero-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #e8edf9;
            padding: 6px 18px 6px 12px;
            border-radius: 40px;
            font-size: 0.8rem;
            font-weight: 600;
            color: #1e3a5f;
            margin-bottom: 24px;
        }
        .hero-badge i {
            color: #2563eb;
            font-size: 0.9rem;
        }
        .hero h1 {
            font-size: 3.8rem;
            font-weight: 800;
            line-height: 1.1;
            letter-spacing: -0.03em;
            margin-bottom: 24px;
            color: #0b1a2f;
        }
        .hero h1 .highlight {
            background: linear-gradient(145deg, #2563eb, #1d4ed8);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .hero p {
            font-size: 1.25rem;
            color: #475569;
            max-width: 520px;
            margin-bottom: 36px;
            line-height: 1.7;
        }
        .hero-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
        }
        .hero-actions .btn-primary {
            padding: 14px 40px;
            font-size: 1rem;
            background: #2563eb;
            box-shadow: 0 8px 24px rgba(37, 99, 235, 0.3);
        }
        .hero-actions .btn-primary:hover {
            background: #1d4ed8;
        }
        .hero-actions .btn-outline {
            padding: 14px 36px;
            font-size: 1rem;
        }

        .hero-visual {
            flex: 1 1 400px;
            background: #eef2f6;
            border-radius: 32px;
            padding: 32px 28px;
            box-shadow: 0 20px 40px -12px rgba(0, 0, 0, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.6);
            backdrop-filter: blur(2px);
        }
        .hero-stats-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        .stat-card {
            background: white;
            border-radius: 20px;
            padding: 22px 18px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.02);
            border: 1px solid #f0f4f9;
            transition: 0.2s;
        }
        .stat-card:hover {
            border-color: #cbd9eb;
        }
        .stat-card .num {
            font-size: 2.2rem;
            font-weight: 700;
            letter-spacing: -0.02em;
            color: #0b1a2f;
        }
        .stat-card .label {
            font-size: 0.85rem;
            color: #64748b;
            font-weight: 500;
            margin-top: 4px;
        }
        .stat-card .trend {
            font-size: 0.75rem;
            font-weight: 600;
            color: #16a34a;
            background: #e6f7ec;
            padding: 2px 12px;
            border-radius: 40px;
            display: inline-block;
            margin-top: 8px;
        }

        /* ---- FEATURES SECTION ---- */
        .features {
            padding: 80px 0 96px;
            border-top: 1px solid rgba(11, 26, 47, 0.04);
        }
        .section-header {
            text-align: center;
            max-width: 700px;
            margin: 0 auto 64px;
        }
        .section-header h2 {
            font-size: 2.8rem;
            font-weight: 700;
            letter-spacing: -0.02em;
            margin-bottom: 16px;
        }
        .section-header p {
            color: #475569;
            font-size: 1.15rem;
        }

        .feature-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 32px;
        }
        .feature-card {
            background: white;
            border-radius: 28px;
            padding: 40px 28px 32px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.02);
            border: 1px solid #f0f4f9;
            transition: 0.25s ease;
        }
        .feature-card:hover {
            transform: translateY(-6px);
            border-color: #dce5f0;
            box-shadow: 0 20px 40px -16px rgba(0, 20, 50, 0.10);
        }
        .feature-icon {
            width: 56px;
            height: 56px;
            border-radius: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            margin-bottom: 22px;
            color: white;
        }
        .feature-icon.blue {
            background: #2563eb;
        }
        .feature-icon.purple {
            background: #7c3aed;
        }
        .feature-icon.green {
            background: #16a34a;
        }
        .feature-icon.orange {
            background: #ea580c;
        }
        .feature-card h3 {
            font-size: 1.3rem;
            font-weight: 700;
            margin-bottom: 12px;
        }
        .feature-card p {
            color: #475569;
            font-size: 0.95rem;
            line-height: 1.7;
            margin-bottom: 18px;
        }
        .feature-tag {
            font-size: 0.75rem;
            font-weight: 600;
            color: #2563eb;
            background: #e8edf9;
            padding: 4px 14px;
            border-radius: 40px;
            display: inline-block;
        }

        /* ---- STATS BANNER ---- */
        .stats-banner {
            background: #0b1a2f;
            border-radius: 40px;
            padding: 56px 48px;
            margin: 24px 0 60px;
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: center;
            gap: 30px;
            color: white;
        }
        .stats-banner .stat-item {
            text-align: center;
            flex: 1 0 120px;
        }
        .stats-banner .stat-item .num {
            font-size: 2.4rem;
            font-weight: 700;
            letter-spacing: -0.02em;
        }
        .stats-banner .stat-item .label {
            font-size: 0.9rem;
            opacity: 0.7;
            font-weight: 400;
        }

        /* ---- HOW IT WORKS (optional) ---- */
        .how-it-works {
            padding: 40px 0 80px;
        }
        .steps {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 40px;
            margin-top: 48px;
        }
        .step {
            text-align: center;
        }
        .step .num-circle {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: #e8edf9;
            color: #1e3a5f;
            font-weight: 700;
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
        }
        .step h4 {
            font-weight: 600;
            margin-bottom: 8px;
        }
        .step p {
            color: #475569;
            font-size: 0.9rem;
        }

        /* ---- FOOTER ---- */
        .footer {
            border-top: 1px solid #e6edf5;
            padding: 40px 0 32px;
            display: flex;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 20px;
            font-size: 0.9rem;
            color: #64748b;
        }
        .footer .social i {
            font-size: 1.2rem;
            margin-left: 18px;
            color: #94a3b8;
            transition: 0.2s;
        }
        .footer .social i:hover {
            color: #0b1a2f;
        }

        /* ---- RESPONSIVE ---- */
        @media (max-width: 768px) {
            .container {
                padding: 0 20px;
            }
            .navbar {
                flex-wrap: wrap;
                gap: 16px;
            }
            .nav-links {
                display: none;
            }
            .hero h1 {
                font-size: 2.5rem;
            }
            .hero {
                padding: 32px 0 48px;
            }
            .stats-banner {
                flex-direction: column;
                padding: 32px 20px;
            }
            .section-header h2 {
                font-size: 2rem;
            }
            .hero-stats-grid {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media (max-width: 480px) {
            .hero-stats-grid {
                grid-template-columns: 1fr;
            }
            .hero-visual {
                padding: 20px 16px;
            }
        }
    </style>
</head>
<body>

<div class="container">

    <!-- ====== NAVIGATION ====== -->
    <nav class="navbar">
        <div class="logo">
            <i class="fas fa-cubes"></i>
            Omni<span>Order</span>
        </div>
{{--        <ul class="nav-links">--}}
{{--            <li><a href="#">Product</a></li>--}}
{{--            <li><a href="#">Solutions</a></li>--}}
{{--            <li><a href="#">Pricing</a></li>--}}
{{--            <li><a href="#">Docs</a></li>--}}
{{--        </ul>--}}
{{--        <div class="nav-actions">--}}
{{--            <button class="btn-outline">Sign in</button>--}}
{{--            <button class="btn-primary"><i class="fas fa-rocket"></i> Start free</button>--}}
{{--        </div>--}}
    </nav>

    <!-- ====== HERO ====== -->
    <section class="hero">
        <div class="hero-text">
            <div class="hero-badge">
                <i class="fas fa-bolt"></i> v3.0 — multi‑merchant ready
            </div>
            <h1>
                Order management<br />
                <span class="highlight">built for scale</span>
            </h1>
            <p>
                Real‑time analytics, granular account control, automated logistics,
                and intelligent payment anomaly detection — all in one platform.
            </p>
            <div class="hero-actions">
                <button class="btn-primary"><i class="fas fa-arrow-right"></i> Explore features</button>
                <button class="btn-outline"><i class="fas fa-play-circle"></i> Watch demo</button>
            </div>
        </div>

        <!-- right side: hero stats cards -->
        <div class="hero-visual">
            <div class="hero-stats-grid">
                <div class="stat-card">
                    <div class="num">$18.4M</div>
                    <div class="label">Total processed</div>
                    <span class="trend"><i class="fas fa-arrow-up"></i> +22%</span>
                </div>
                <div class="stat-card">
                    <div class="num">2.3k</div>
                    <div class="label">Active merchants</div>
                    <span class="trend" style="color:#2563eb; background:#e8edf9;"><i class="fas fa-users"></i> +18%</span>
                </div>
                <div class="stat-card">
                    <div class="num">1.8M</div>
                    <div class="label">Orders synced</div>
                    <span class="trend"><i class="fas fa-check-circle"></i> 99.9%</span>
                </div>
                <div class="stat-card">
                    <div class="num">94%</div>
                    <div class="label">Anomaly detection</div>
                    <span class="trend" style="color:#ea580c; background:#fef1e6;"><i class="fas fa-shield-alt"></i> real‑time</span>
                </div>
            </div>
        </div>
    </section>

    <!-- ====== STATS BANNER ====== -->
    <div class="stats-banner">
        <div class="stat-item">
            <div class="num">99.97%</div>
            <div class="label">Uptime SLA</div>
        </div>
        <div class="stat-item">
            <div class="num">4.2s</div>
            <div class="label">Avg. order sync</div>
        </div>
        <div class="stat-item">
            <div class="num">128</div>
            <div class="label">Countries supported</div>
        </div>
        <div class="stat-item">
            <div class="num">24/7</div>
            <div class="label">Anomaly monitoring</div>
        </div>
    </div>

    <!-- ====== FEATURES (4 CORE HIGHLIGHTS) ====== -->
    <section class="features">
        <div class="section-header">
            <h2>Built for modern commerce operations</h2>
            <p>Every feature is designed to give you full visibility and control across your merchant ecosystem.</p>
        </div>

        <div class="feature-grid">

            <!-- 1. Data & Analytics -->
            <div class="feature-card">
                <div class="feature-icon blue"><i class="fas fa-chart-pie"></i></div>
                <h3>Live data &amp; analytics</h3>
                <p>
                    Real‑time dashboards with custom filters. Track order volume, revenue,
                    conversion rates, and merchant performance at a glance.
                </p>
                <span class="feature-tag"><i class="fas fa-arrow-right"></i> 30+ metrics</span>
            </div>

            <!-- 2. Sub‑account management -->
            <div class="feature-card">
                <div class="feature-icon purple"><i class="fas fa-user-cog"></i></div>
                <h3>Granular sub‑account control</h3>
                <p>
                    Create merchant sub‑accounts with custom roles: Order Admin, Logistics,
                    App Manager, and more. Isolate data per tenant automatically.
                </p>
                <span class="feature-tag"><i class="fas fa-shield-alt"></i> RBAC + MFA</span>
            </div>

            <!-- 3. Logistics auto‑sync -->
            <div class="feature-card">
                <div class="feature-icon green"><i class="fas fa-truck-fast"></i></div>
                <h3>Logistics auto‑sync</h3>
                <p>
                    Upload shipment files → OSS storage → queue processing → automatic
                    tracking number sync. Hook system ready for custom workflows.
                </p>
                <span class="feature-tag"><i class="fas fa-cloud-upload-alt"></i> OSS native</span>
            </div>

            <!-- 4. Payment anomaly handling -->
            <div class="feature-card">
                <div class="feature-icon orange"><i class="fas fa-exclamation-triangle"></i></div>
                <h3>Payment anomaly detection</h3>
                <p>
                    Real‑time risk rules: per‑transaction limits, daily/monthly caps, and
                    automated fallback payment methods. Zero‑click incident alerting.
                </p>
                <span class="feature-tag"><i class="fas fa-bolt"></i> 5‑second detection</span>
            </div>

        </div>
    </section>

    <!-- ====== HOW IT WORKS (optional extra) ====== -->
    <section class="how-it-works">
        <div class="section-header">
            <h2>How it works in practice</h2>
            <p>From merchant onboarding to order fulfillment — fully automated.</p>
        </div>
        <div class="steps">
            <div class="step">
                <div class="num-circle">1</div>
                <h4>Connect merchants</h4>
                <p>Invite merchants, assign roles, and configure payment groups per brand.</p>
            </div>
            <div class="step">
                <div class="num-circle">2</div>
                <h4>Sync orders &amp; events</h4>
                <p>API or manual creation → real‑time event logs → auto‑webhook dispatch.</p>
            </div>
            <div class="step">
                <div class="num-circle">3</div>
                <h4>Logistics &amp; notifications</h4>
                <p>Bulk upload tracking → OSS sync → Telegram &amp; email alerts.</p>
            </div>
            <div class="step">
                <div class="num-circle">4</div>
                <h4>Monitor &amp; optimize</h4>
                <p>Live analytics, anomaly reports, and smart payment routing.</p>
            </div>
        </div>
    </section>

    <!-- ====== FOOTER ====== -->
    <div class="footer">
        <div>© {{date('Y')}} OmniOrder — built for merchants</div>
        <div class="social">
            <i class="fab fa-twitter"></i>
            <i class="fab fa-github"></i>
            <i class="fab fa-linkedin-in"></i>
            <i class="fab fa-youtube"></i>
        </div>
    </div>

</div>
<!-- end container -->

</body>
</html>