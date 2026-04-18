<?php
require_once '../includes/config.php';

// Redirect if already logged in
if (isset($_SESSION['nurse_id'])) {
    header('Location: dashboard.php');
    exit();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    
    if (empty($email) || empty($password)) {
        $error = "Email and password are required";
    } else {
        // Fetch nurse by email ONLY (don't compare password in SQL)
        $sql = "SELECT * FROM nurses WHERE email = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$email]);
        $nurse = $stmt->fetch();
        
        // Verify the hashed password using password_verify()
        if ($nurse && password_verify($password, $nurse['password'])) {
            // Login successful
            $_SESSION['nurse_id'] = $nurse['nurse_id'];
            $_SESSION['nurse_name'] = $nurse['first_name'] . ' ' . $nurse['last_name'];
            $_SESSION['user_type'] = 'nurse';
            
            header('Location: dashboard.php');
            exit();
        } else {
            $error = "Invalid email or password";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Nurse Login | Medical Practice</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(125deg, #e0f0ff 0%, #f5f0fc 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 1.5rem;
            position: relative;
        }

        body::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: radial-gradient(circle at 20% 80%, rgba(72, 187, 120, 0.08) 0%, transparent 50%);
            pointer-events: none;
        }

        .login-card {
            background: white;
            border-radius: 2rem;
            box-shadow: 0 25px 45px -12px rgba(0, 0, 0, 0.15);
            width: 100%;
            max-width: 460px;
            padding: 2.2rem 2rem;
            border: 1px solid rgba(72, 187, 120, 0.2);
            position: relative;
            z-index: 2;
        }

        .portal-badge {
            display: inline-block;
            background: #e8f5e8;
            padding: 0.4rem 1rem;
            border-radius: 40px;
            font-size: 0.75rem;
            font-weight: 700;
            color: #2e7d32;
            margin-bottom: 1.2rem;
        }

        .login-card h2 {
            font-size: 1.8rem;
            font-weight: 800;
            background: linear-gradient(120deg, #1e2a3e, #2d3a5e);
            background-clip: text;
            -webkit-background-clip: text;
            color: transparent;
            margin-bottom: 0.5rem;
        }

        .welcome-text {
            color: #5b6e8c;
            font-size: 0.9rem;
            margin-bottom: 1.8rem;
            line-height: 1.4;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: #1f2a44;
            font-size: 0.85rem;
        }

        .input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-wrapper i {
            position: absolute;
            left: 1rem;
            color: #a0b3d9;
            font-size: 1rem;
            transition: color 0.2s;
        }

        input {
            width: 100%;
            padding: 0.85rem 1rem 0.85rem 2.8rem;
            border: 1.5px solid #e2e8f0;
            border-radius: 1rem;
            font-size: 0.95rem;
            font-family: 'Inter', sans-serif;
            transition: all 0.2s ease;
            background: #fefefe;
            outline: none;
            color: #1a2c3e;
            font-weight: 500;
        }

        input:focus {
            border-color: #48bb78;
            box-shadow: 0 0 0 3px rgba(72, 187, 120, 0.15);
            background: white;
        }

        input:focus + i {
            color: #48bb78;
        }

        .error {
            background: #fff1f0;
            color: #d9534f;
            padding: 0.85rem 1rem;
            border-radius: 1rem;
            margin-bottom: 1.5rem;
            border-left: 4px solid #f56565;
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .login-btn {
            width: 100%;
            background: linear-gradient(95deg, #48bb78, #38a169);
            color: white;
            border: none;
            border-radius: 60px;
            padding: 0.9rem;
            font-weight: 700;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.25s ease;
            font-family: 'Inter', sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-top: 0.5rem;
        }

        .login-btn:hover {
            background: linear-gradient(95deg, #38a169, #2f855a);
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(72, 187, 120, 0.35);
        }

        .login-btn:active {
            transform: translateY(1px);
        }

        .back-link {
            text-align: center;
            margin-top: 1.5rem;
            font-size: 0.9rem;
        }

        .back-link a {
            color: #48bb78;
            text-decoration: none;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
        }

        .back-link a:hover {
            color: #2f855a;
            gap: 10px;
        }

        .secure-note {
            text-align: center;
            margin-top: 1.2rem;
            font-size: 0.7rem;
            color: #94a3b8;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }

        @media (max-width: 560px) {
            .login-card {
                padding: 1.8rem 1.4rem;
            }
            .login-card h2 {
                font-size: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="portal-badge">
            <i class="fas fa-user-nurse" style="margin-right: 6px;"></i> Staff Portal
        </div>
        
        <h2>Nurse Login</h2>
        <div class="welcome-text">
            Please sign in to manage appointments, confirm bookings, and access patient schedules.
        </div>

        <?php if ($error): ?>
            <div class="error">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        
        <form method="POST" action="">
            <div class="form-group">
                <label>Email Address</label>
                <div class="input-wrapper">
                    <i class="fas fa-envelope"></i>
                    <input type="email" name="email" placeholder="nurse@medicalpractice.com" required>
                </div>
            </div>
            
            <div class="form-group">
                <label>Password</label>
                <div class="input-wrapper">
                    <i class="fas fa-lock"></i>
                    <input type="password" name="password" placeholder="Enter your password" required>
                </div>
            </div>
            
            <button type="submit" class="login-btn">
                <i class="fas fa-sign-in-alt"></i> Sign In
            </button>
        </form>
        
        <div class="back-link">
            <a href="../index.php">
                <i class="fas fa-arrow-left"></i> Back to Home
            </a>
        </div>
        
       
    </div>
</body>
</html>
