<?php
session_start();
$error = '';
$success = '';

$API_URL = 'https://medical-bot.ouamanenadia041.workers.dev/api/register';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $date_of_birth = $_POST['date_of_birth'] ?? '';
    
    if (empty($first_name) || empty($last_name) || empty($email) || empty($password)) {
        $error = "All fields are required";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters";
    } else {
        $data = json_encode([
            'first_name' => $first_name,
            'last_name' => $last_name,
            'email' => $email,
            'phone' => $phone,
            'password' => $password,
            'date_of_birth' => $date_of_birth
        ]);
        
        $ch = curl_init($API_URL);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $result = json_decode($response, true);
        
        if ($httpCode === 200 && $result['success']) {
            $success = "Registration successful! You can now login.";
            $_POST = array();
        } else {
            $error = $result['error'] ?? "Registration failed. Please try again.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Registration | Medical Practice</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(125deg, #e0f0ff 0%, #f5f0fc 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 1.5rem;
        }
        .register-card {
            background: white;
            border-radius: 2rem;
            box-shadow: 0 25px 45px -12px rgba(0, 0, 0, 0.15);
            width: 100%;
            max-width: 520px;
            padding: 2.2rem 2rem;
            border: 1px solid rgba(102, 126, 234, 0.15);
        }
        .portal-badge {
            display: inline-block;
            background: #eef2ff;
            padding: 0.4rem 1rem;
            border-radius: 40px;
            font-size: 0.75rem;
            font-weight: 700;
            color: #4f46e5;
            margin-bottom: 1.2rem;
        }
        h2 {
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
            margin-bottom: 1.5rem;
        }
        .form-group { margin-bottom: 1.2rem; }
        label { display: block; margin-bottom: 0.4rem; font-weight: 600; color: #1f2a44; font-size: 0.85rem; }
        .input-wrapper { position: relative; display: flex; align-items: center; }
        .input-wrapper i { position: absolute; left: 1rem; color: #a0b3d9; font-size: 1rem; }
        input {
            width: 100%;
            padding: 0.8rem 1rem 0.8rem 2.8rem;
            border: 1.5px solid #e2e8f0;
            border-radius: 1rem;
            font-size: 0.9rem;
            font-family: 'Inter', sans-serif;
            transition: all 0.2s ease;
            outline: none;
        }
        input:focus { border-color: #667eea; box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.15); }
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
        .success {
            background: #e8f5e8;
            color: #2e7d32;
            padding: 0.85rem 1rem;
            border-radius: 1rem;
            margin-bottom: 1.5rem;
            border-left: 4px solid #48bb78;
            font-size: 0.85rem;
        }
        .register-btn {
            width: 100%;
            background: linear-gradient(95deg, #4f46e5, #7c3aed);
            color: white;
            border: none;
            border-radius: 60px;
            padding: 0.9rem;
            font-weight: 700;
            font-size: 1rem;
            cursor: pointer;
            margin-top: 0.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        .register-btn:hover {
            background: linear-gradient(95deg, #4338ca, #6d28d9);
            transform: translateY(-2px);
        }
        .login-link { text-align: center; margin-top: 1.5rem; font-size: 0.9rem; }
        .login-link a { color: #4f46e5; text-decoration: none; font-weight: 700; }
    </style>
</head>
<body>
    <div class="register-card">
        <div class="portal-badge"><i class="fas fa-user-plus"></i> New Patient Registration</div>
        <h2>Create Account</h2>
        <div class="welcome-text">Join our Shifa Medical Center to manage appointments and receive quality care.</div>

        <?php if ($error): ?>
            <div class="error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label>First Name</label>
                <div class="input-wrapper">
                    <i class="fas fa-user"></i>
                    <input type="text" name="first_name" required>
                </div>
            </div>
            <div class="form-group">
                <label>Last Name</label>
                <div class="input-wrapper">
                    <i class="fas fa-user"></i>
                    <input type="text" name="last_name" required>
                </div>
            </div>
            <div class="form-group">
                <label>Email Address</label>
                <div class="input-wrapper">
                    <i class="fas fa-envelope"></i>
                    <input type="email" name="email" required>
                </div>
            </div>
            <div class="form-group">
                <label>Phone Number</label>
                <div class="input-wrapper">
                    <i class="fas fa-phone"></i>
                    <input type="tel" name="phone" required>
                </div>
            </div>
            <div class="form-group">
                <label>Date of Birth</label>
                <div class="input-wrapper">
                    <i class="fas fa-calendar"></i>
                    <input type="date" name="date_of_birth" required>
                </div>
            </div>
            <div class="form-group">
                <label>Password (min 6 characters)</label>
                <div class="input-wrapper">
                    <i class="fas fa-key"></i>
                    <input type="password" name="password" required>
                </div>
            </div>
            <div class="form-group">
                <label>Confirm Password</label>
                <div class="input-wrapper">
                    <i class="fas fa-check-circle"></i>
                    <input type="password" name="confirm_password" required>
                </div>
            </div>
            <button type="submit" class="register-btn"><i class="fas fa-user-plus"></i> Register Now</button>
        </form>
        <div class="login-link"><i class="fas fa-sign-in-alt"></i> Already have an account? <a href="login.php">Login here</a></div>
    </div>
</body>
</html>
