<?php
require_once '../includes/config.php';

// Redirect if already logged in
if (isset($_SESSION['doctor_id'])) {
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
        // Fetch doctor by email ONLY (not with password)
        $sql = "SELECT * FROM doctors WHERE email = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$email]);
        $doctor = $stmt->fetch();
        
        // Verify the hashed password
        if ($doctor && password_verify($password, $doctor['password'])) {
            $_SESSION['doctor_id'] = $doctor['doctor_id'];
            $_SESSION['doctor_name'] = 'Dr. ' . $doctor['first_name'] . ' ' . $doctor['last_name'];
            $_SESSION['user_type'] = 'doctor';
            
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Doctor Login</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: Arial, sans-serif;
            background: linear-gradient(135deg, #f56565 0%, #c53030 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
        }
        .container {
            background: white;
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            width: 100%;
            max-width: 400px;
        }
        h2 {
            text-align: center;
            color: #333;
            margin-bottom: 30px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            margin-bottom: 5px;
            color: #555;
            font-weight: bold;
        }
        input {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 16px;
        }
        button {
            width: 100%;
            padding: 12px;
            background: #f56565;
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
        }
        .error {
            background: #fee;
            color: #c33;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .back-link {
            text-align: center;
            margin-top: 20px;
        }
        .back-link a {
            color: #f56565;
            text-decoration: none;
        }
        
        /* Back to Home Button */
        .back-home {
            text-align: center;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #eee;
        }
        
        .back-home a {
            color: #666;
            text-decoration: none;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: color 0.3s;
        }
        
        .back-home a:hover {
            color: #f56565;
        }
    </style>
</head>
<body>
    <div class="container">
        <h2>👨‍⚕️ Doctor Login</h2>
        
        <?php if ($error): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <form method="POST" action="">
            <div class="form-group">
                <label>Email:</label>
                <input type="email" name="email" required>
            </div>
            
            <div class="form-group">
                <label>Password:</label>
                <input type="password" name="password" required>
            </div>
            
            <button type="submit">Login</button>
        </form>
        
        <div class="back-link">
            <a href="../index.php">← Back to Home</a>
        </div>
        
        <!-- Back to Home Button -->
       <!-- <div class="back-home">
            <a href="http://localhost/medical_practice/index.php">← Back to Main Home</a>
        </div>-->
    </div>
</body>
</html>
