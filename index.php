<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Medical Practice - Home</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
        }
        
        .container {
            text-align: center;
            background: white;
            padding: 50px;
            border-radius: 10px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            max-width: 600px;
            width: 90%;
        }
        
        h1 {
            color: #333;
            margin-bottom: 20px;
            font-size: 2.5em;
        }
        
        p {
            color: #666;
            margin-bottom: 30px;
            font-size: 1.2em;
            line-height: 1.6;
        }
        
        .buttons {
            display: flex;
            gap: 20px;
            justify-content: center;
            flex-wrap: wrap;
        }
        
        .btn {
            padding: 15px 30px;
            border-radius: 5px;
            text-decoration: none;
            font-weight: bold;
            transition: transform 0.3s, box-shadow 0.3s;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        
        .btn-patient {
            background: #667eea;
            color: white;
        }
        
        .btn-nurse {
            background: #48bb78;
            color: white;
        }
        
        .btn-doctor {
            background: #f56565;
            color: white;
        }
        
        .footer {
            margin-top: 30px;
            color: #999;
            font-size: 0.9em;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🏥 Medical Practice</h1>
        <p>Welcome to our medical practice management system. Please select your login portal:</p>
        
        <div class="buttons">
            <a href="patient/login.php" class="btn btn-patient">Patient Portal</a>
            <a href="nurse/login.php" class="btn btn-nurse">Nurse Portal</a>
            <a href="doctor/login.php" class="btn btn-doctor">Doctor Portal</a>
        </div>
        
        <div class="footer">
            &copy; 2026 Medical Practice. All rights reserved.
        </div>
    </div>
</body>
</html>
