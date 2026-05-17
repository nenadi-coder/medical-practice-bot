 <!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Shifa Medical Center  Quality Healthcare for You & Family</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(125deg, #e9f0fc 0%, #f1ebfa 100%);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: #1e2a3e;
            padding: 2rem 1rem;
        }

        /* MAIN CONTAINER - CENTERED with max-width */
        .main-container {
            max-width: 1200px;
            width: 100%;
            margin: 0 auto;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }

        /* All cards and sections share same centered alignment */
        .hero {
            background: linear-gradient(135deg, #FFFFFF 0%, #F9FBFE 100%);
            border-radius: 2rem;
            padding: 2.5rem 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 20px 35px -12px rgba(0, 0, 0, 0.08);
            border: 1px solid rgba(102, 126, 234, 0.15);
            text-align: center;
            width: 100%;
            transition: all 0.2s ease;
        }

        .hero-badge {
            display: inline-block;
            background: #eef2ff;
            padding: 0.4rem 1.2rem;
            border-radius: 40px;
            font-size: 0.8rem;
            font-weight: 700;
            letter-spacing: 0.3px;
            color: #4f46e5;
            margin-bottom: 1.2rem;
            backdrop-filter: blur(2px);
        }

        .hero h1 {
            font-size: 3.5rem;
            font-weight: 800;
            background: linear-gradient(120deg, #1e2a3e, #2d3a5e);
            background-clip: text;
            -webkit-background-clip: text;
            color: transparent;
            letter-spacing: -0.02em;
            margin-bottom: 1rem;
        }

        .hero .tagline {
            font-size: 1.2rem;
            color: #2c3e50;
            max-width: 700px;
            margin: 0 auto 1.5rem auto;
            font-weight: 400;
            line-height: 1.5;
            border-left: none;
            padding-left: 0;
            text-align: center;
        }

        .contact-row {
            display: flex;
            flex-wrap: wrap;
            gap: 1.5rem;
            justify-content: center;
            margin-top: 1rem;
        }

        .contact-item {
            display: flex;
            align-items: center;
            gap: 0.7rem;
            font-size: 1rem;
            color: #1f2b38;
            font-weight: 500;
            background: white;
            padding: 0.6rem 1.3rem;
            border-radius: 60px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
            transition: all 0.2s;
        }

        .contact-item i {
            color: #4f46e5;
            font-size: 1.1rem;
            width: 1.4rem;
        }

        .hours {
            background: #f0f4fe;
        }

        /* WHY CHOOSE US section - centered layout */
        .why-section {
            background: white;
            border-radius: 2rem;
            padding: 2.5rem 2rem;
            margin-bottom: 2rem;
            width: 100%;
            text-align: center;
            box-shadow: 0 15px 35px -12px rgba(0, 0, 0, 0.05);
            border: 1px solid rgba(0,0,0,0.03);
        }

        .why-section h2 {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 2rem;
            color: #0f172a;
            display: inline-block;
            position: relative;
        }

        .why-section h2:after {
            content: '';
            position: absolute;
            bottom: -10px;
            left: 50%;
            transform: translateX(-50%);
            width: 70px;
            height: 4px;
            background: linear-gradient(90deg, #667eea, #a07bd7);
            border-radius: 4px;
        }

        .features-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 2rem;
            justify-content: center;
            margin: 2rem 0 1rem;
        }

        .feature-card {
            flex: 1;
            min-width: 230px;
            max-width: 280px;
            background: #fafcff;
            padding: 1.8rem 1.2rem;
            border-radius: 1.5rem;
            transition: all 0.2s ease;
            text-align: center;
            border: 1px solid #eef2ff;
        }

        .feature-card:hover {
            transform: translateY(-6px);
            box-shadow: 0 20px 25px -12px rgba(102, 126, 234, 0.2);
            border-color: #cbdffc;
        }

        .feature-icon {
            font-size: 2.5rem;
            background: linear-gradient(145deg, #667eea, #a17fe0);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            margin-bottom: 1rem;
        }

        .feature-card h3 {
            font-weight: 700;
            font-size: 1.4rem;
            margin-bottom: 0.6rem;
            color: #1e293b;
        }

        .feature-card p {
            color: #475569;
            line-height: 1.4;
            font-size: 0.95rem;
        }

        .address-mini {
            margin-top: 1.5rem;
            font-size: 0.85rem;
            color: #5b6e8c;
            text-align: center;
        }

        /* PORTAL SECTION - centered call to action */
        .portal-section {
            background: linear-gradient(115deg, #fef9ff 0%, #f2f5ff 100%);
            border-radius: 2rem;
            padding: 2.2rem 2rem;
            margin-bottom: 2rem;
            width: 100%;
            text-align: center;
            box-shadow: 0 20px 30px -10px rgba(0,0,0,0.05);
            border: 1px solid rgba(102,126,234,0.2);
        }

        .portal-section h3 {
            font-size: 1.9rem;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 0.75rem;
        }

        .portal-section p {
            color: #2d3a5e;
            margin-bottom: 2rem;
            font-size: 1.05rem;
            max-width: 550px;
            margin-left: auto;
            margin-right: auto;
        }

        .buttons {
            display: flex;
            gap: 1.5rem;
            justify-content: center;
            flex-wrap: wrap;
        }

        .btn {
            padding: 1rem 2rem;
            border-radius: 60px;
            text-decoration: none;
            font-weight: 700;
            font-size: 1.05rem;
            transition: all 0.25s ease;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            letter-spacing: 0.3px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.02);
        }

        .btn i {
            font-size: 1.1rem;
        }

        .btn-patient {
            background: #4f46e5;
            color: white;
            border: 1px solid #6366f1;
        }

        .btn-patient:hover {
            background: #4338ca;
            transform: scale(1.02);
            box-shadow: 0 12px 20px -12px #4f46e5;
        }

        .btn-nurse {
            background: #0d9488;
            color: white;
            border: 1px solid #14b8a6;
        }

        .btn-nurse:hover {
            background: #0f766e;
            transform: scale(1.02);
            box-shadow: 0 12px 20px -12px #0d9488;
        }

        .btn-doctor {
            background: #dc2626;
            color: white;
            border: 1px solid #ef4444;
        }

        .btn-doctor:hover {
            background: #b91c1c;
            transform: scale(1.02);
            box-shadow: 0 12px 20px -12px #dc2626;
        }

        /* FOOTER area - fully centered & elegant */
        .footer {
            background: #ffffffdd;
            backdrop-filter: blur(8px);
            border-radius: 2rem;
            padding: 1.5rem 2rem;
            width: 100%;
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            font-size: 0.9rem;
            color: #2c3e66;
            border: 1px solid #e2e8f0;
            margin-top: 0.5rem;
        }

        .footer-left p {
            margin: 0;
            font-weight: 500;
        }

        .footer-left i {
            margin-right: 6px;
            color: #4f46e5;
        }

        .footer-right {
            display: flex;
            gap: 1.2rem;
            flex-wrap: wrap;
        }

        .footer-right span {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .copyright {
            text-align: center;
            padding: 1rem 0 0.5rem;
            font-size: 0.8rem;
            color: #4a5b6e;
            width: 100%;
            margin-top: 0.25rem;
        }

        @media (max-width: 780px) {
            .hero h1 {
                font-size: 2.3rem;
            }
            .hero .tagline {
                font-size: 1rem;
                max-width: 100%;
            }
            .features-grid {
                flex-direction: column;
                align-items: center;
            }
            .feature-card {
                max-width: 100%;
                width: 100%;
            }
            .footer {
                flex-direction: column;
                text-align: center;
                justify-content: center;
            }
            .contact-row {
                justify-content: center;
            }
            .portal-section h3 {
                font-size: 1.5rem;
            }
            .btn {
                padding: 0.8rem 1.5rem;
            }
        }

        /* additional subtle accent */
        i.fa, i.far, i.fas {
            pointer-events: none;
        }
        .accent-border {
            border-bottom: 2px solid #eef2ff;
        }
    </style>
</head>
<body>
<div class="main-container">
    <!-- Hero Section: "Shifa Medical Center" + tagline centered exactly as requested -->
    <div class="hero">
        <div class="hero-badge">
            <i class="fas fa-stethoscope"></i> Now Accepting New Patients
        </div>
        <h1>Shifa Medical Center</h1>
        <div class="tagline">
            Quality healthcare for you and your family. Our experienced team provides compassionate, comprehensive medical services.
        </div>
        <div class="contact-row">
            <div class="contact-item">
                <i class="fas fa-phone-alt"></i> <span>033 74 15 27</span>
            </div>
            <div class="contact-item hours">
                <i class="far fa-clock"></i> <span>Sun-Thu: 8AM–5PM</span>
            </div>
            <div class="contact-item">
                <i class="fas fa-map-marker-alt"></i> <span>123 Health Street, Medical City</span>
            </div>
        </div>
    </div>

    <!-- Why Choose Us section - exactly from the provided images -->
    <div class="why-section">
        <h2>Why Choose Us</h2>
        <div class="features-grid">
            <div class="feature-card">
                <div class="feature-icon"><i class="fas fa-user-md"></i></div>
                <h3>Expert Team</h3>
                <p>Board-certified physician with decades of experience</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon"><i class="fas fa-file-invoice-dollar"></i></div>
                <h3>Insurance Accepted</h3>
                <p>We accept most major insurance plans "CNAS/CASNOS"</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon"><i class="fas fa-heartbeat"></i></div>
                <h3>Patient-Centered</h3>
                <p>Your health and comfort are our top priorities</p>
            </div>
        </div>
        <div class="address-mini">
            <i class="fas fa-location-dot"></i> 123 Health Street, Medical City &nbsp;|&nbsp; 
            <i class="fas fa-phone"></i> (033 74 15 27)
        </div>
    </div>

    <!-- Portal Selection - Patient, Nurse, Doctor, perfectly centered -->
    <div class="portal-section">
        <h3><i class="fas fa-users" style="margin-right: 12px;"></i> Secure Portal Access</h3>
        <p>Manage appointments, health records, and communicate with your care team</p>
        <div class="buttons">
            <a href="patient/login.php" class="btn btn-patient"><i class="fas fa-user-circle"></i> Patient Portal</a>
            <a href="nurse/login.php" class="btn btn-nurse"><i class="fas fa-user-nurse"></i> Nurse Portal</a>
            <a href="doctor/login.php" class="btn btn-doctor"><i class="fas fa-user-md"></i> Doctor Portal</a>
        </div>
    </div>

    <!-- Footer that combines copyright, address, contact, hours from both images -->
    <div class="footer">
        <div class="footer-left">
            <p><i class="far fa-copyright"></i> 2026 Shifa Medical Center. All rights reserved.</p>
        </div>
        <div class="footer-right">
            <span><i class="fas fa-map-pin"></i> 123 Health Street, Medical City</span>
            <span><i class="fas fa-phone-alt"></i> 033 74 15 27</span>
            <span><i class="far fa-calendar-alt"></i> Sun-Thu: 8AM–5PM</span>
        </div>
    </div>
    <div class="copyright">
        <i class="fas fa-shield-alt"></i> your health, our mission
    </div>
</div>
</body>
</html> 
