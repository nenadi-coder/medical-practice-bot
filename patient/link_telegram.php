<?php
session_start();
require_once 'includes/config.php';

// Check if patient is logged in
if (!isset($_SESSION['patient_id'])) {
    die("Please login first");
}

$patient_id = $_SESSION['patient_id'];

// Get the linking code from POST
$code = isset($_POST['code']) ? $_POST['code'] : '';

if ($code) {
    // Save the code temporarily
    $_SESSION['linking_code'] = $code;
    echo "Code saved! Now send this code to the Telegram bot.";
} else {
    // Generate a random code
    $code = strtoupper(substr(md5(uniqid()), 0, 8));
    $_SESSION['linking_code'] = $code;
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Link Telegram</title>
        <style>
            body { font-family: Arial; background: #f0f2f5; display: flex; justify-content: center; align-items: center; height: 100vh; }
            .card { background: white; padding: 30px; border-radius: 10px; text-align: center; max-width: 400px; }
            .code { font-size: 32px; font-weight: bold; background: #f0f2f5; padding: 20px; border-radius: 10px; margin: 20px 0; letter-spacing: 5px; }
            .btn { background: #0088cc; color: white; padding: 12px 24px; border: none; border-radius: 5px; cursor: pointer; }
        </style>
    </head>
    <body>
        <div class="card">
            <h2>🔗 Link Your Telegram Account</h2>
            <p>1. Open Telegram and find <strong>@MedicalPracticeBot</strong></p>
            <p>2. Send this code to the bot:</p>
            <div class="code"><?php echo $code; ?></div>
            <p>3. The bot will link your account automatically</p>
            <button class="btn" onclick="checkStatus()">Check Status</button>
        </div>
        
        <script>
        function checkStatus() {
            setInterval(function() {
                fetch('check_linked.php')
                    .then(r => r.json())
                    .then(data => {
                        if (data.linked) {
                            alert('✅ Telegram linked successfully!');
                            window.location.href = 'dashboard.php';
                        }
                    });
            }, 3000);
        }
        </script>
    </body>
    </html>
    <?php
}
?>
