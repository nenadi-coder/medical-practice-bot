<?php

require_once '../includes/config.php';

// ADDED SYNC API KEY
define('SYNC_API_KEY', 'nadia');

const SYNC_FUNCTION_URL = 'https://auvglgofkuihkzxledtw.supabase.co/functions/v1/sync-data';

$error = '';
$success = '';
$syncStatus = '';

$form = [
    'first_name' => '',
    'last_name' => '',
    'email' => '',
    'phone' => '',
    'date_of_birth' => '',
];

function getPostedValue(string $key): string
{
    return isset($_POST[$key]) ? trim((string) $_POST[$key]) : '';
}

function resetForm(): array
{
    return [
        'first_name' => '',
        'last_name' => '',
        'email' => '',
        'phone' => '',
        'date_of_birth' => '',
    ];
}

function getSyncApiKey(): string
{
    if (defined('SYNC_API_KEY') && SYNC_API_KEY) {
        return trim((string) SYNC_API_KEY);
    }

    $envValue = getenv('SYNC_API_KEY');
    return $envValue !== false ? trim((string) $envValue) : '';
}

function parseHttpStatusCode(array $headers): int
{
    foreach ($headers as $headerLine) {
        if (preg_match('#HTTP/\S+\s+(\d{3})#', $headerLine, $matches)) {
            return (int) $matches[1];
        }
    }

    return 0;
}

function syncPatientToTelegramDatabase(array $patient): array
{
    $syncApiKey = getSyncApiKey();

    if ($syncApiKey === '') {
        return [
            'ok' => false,
            'message' => 'SYNC_API_KEY is missing on the website server.',
        ];
    }

    $payload = json_encode([
        'action' => 'sync_patient',
        'first_name' => $patient['first_name'],
        'last_name' => $patient['last_name'],
        'email' => $patient['email'],
        'phone' => $patient['phone'],
        'date_of_birth' => $patient['date_of_birth'],
    ]);

    if ($payload === false) {
        return [
            'ok' => false,
            'message' => 'Failed to encode sync payload.',
        ];
    }

    $responseBody = '';
    $statusCode = 0;

    if (function_exists('curl_init')) {
        $ch = curl_init(SYNC_FUNCTION_URL);

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-sync-key: ' . $syncApiKey,
            ],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
        ]);

        $responseBody = curl_exec($ch);

        if ($responseBody === false) {
            $curlError = curl_error($ch);
            curl_close($ch);

            return [
                'ok' => false,
                'message' => 'Telegram sync request failed: ' . $curlError,
            ];
        }

        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", [
                    'Content-Type: application/json',
                    'x-sync-key: ' . $syncApiKey,
                ]),
                'content' => $payload,
                'timeout' => 20,
                'ignore_errors' => true,
            ],
        ]);

        $responseBody = @file_get_contents(SYNC_FUNCTION_URL, false, $context);
        $responseHeaders = isset($http_response_header) && is_array($http_response_header)
            ? $http_response_header
            : [];
        $statusCode = parseHttpStatusCode($responseHeaders);

        if ($responseBody === false) {
            return [
                'ok' => false,
                'message' => 'Telegram sync request failed and no response was returned.',
            ];
        }
    }

    $decoded = json_decode($responseBody, true);
    $remoteError = is_array($decoded) && isset($decoded['error'])
        ? (string) $decoded['error']
        : 'Unknown sync error.';

    if ($statusCode < 200 || $statusCode >= 300) {
        return [
            'ok' => false,
            'message' => 'Telegram sync failed: ' . $remoteError,
        ];
    }

    if (!is_array($decoded) || empty($decoded['ok'])) {
        return [
            'ok' => false,
            'message' => 'Telegram sync failed: ' . $remoteError,
        ];
    }

    return [
        'ok' => true,
        'message' => 'Patient synced to Telegram database.',
        'data' => $decoded,
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form = [
        'first_name' => getPostedValue('first_name'),
        'last_name' => getPostedValue('last_name'),
        'email' => strtolower(getPostedValue('email')),
        'phone' => getPostedValue('phone'),
        'date_of_birth' => getPostedValue('date_of_birth'),
    ];

    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if ($form['first_name'] === '' || $form['last_name'] === '' || $form['email'] === '' || $password === '') {
        $error = 'All fields are required';
    } elseif (!filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address';
    } elseif ($form['phone'] === '') {
        $error = 'Phone is required';
    } elseif ($form['date_of_birth'] === '') {
        $error = 'Date of birth is required';
    } elseif ($password !== $confirmPassword) {
        $error = 'Passwords do not match';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters';
    } else {
        try {
            $pdo->beginTransaction();

            $checkSql = 'SELECT patient_id FROM patients WHERE LOWER(email) = ? LIMIT 1';
            $checkStmt = $pdo->prepare($checkSql);
            $checkStmt->execute([$form['email']]);

            if ($checkStmt->fetch()) {
                $pdo->rollBack();
                $error = 'Email already registered';
            } else {
                $insertSql = 'INSERT INTO patients (first_name, last_name, email, phone, password, date_of_birth) VALUES (?, ?, ?, ?, ?, ?)';
                $insertStmt = $pdo->prepare($insertSql);

                $inserted = $insertStmt->execute([
                    $form['first_name'],
                    $form['last_name'],
                    $form['email'],
                    $form['phone'],
                    $password,
                    $form['date_of_birth'],
                ]);

                if (!$inserted) {
                    throw new RuntimeException('Registration failed while saving the patient.');
                }

                $syncResult = syncPatientToTelegramDatabase($form);

                if (!$syncResult['ok']) {
                    throw new RuntimeException($syncResult['message']);
                }

                $pdo->commit();
                $success = 'Registration successful! You can now login.';
               // $syncStatus = '✅ Patient synced to Telegram database.';
                $form = resetForm();
                $_POST = [];
            }
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $error = $exception->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Patient Registration | Medical Practice</title>
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
            background: radial-gradient(circle at 20% 80%, rgba(102, 126, 234, 0.05) 0%, transparent 50%);
            pointer-events: none;
        }

        .register-card {
            background: white;
            border-radius: 2rem;
            box-shadow: 0 25px 45px -12px rgba(0, 0, 0, 0.15);
            width: 100%;
            max-width: 520px;
            padding: 2.2rem 2rem;
            border: 1px solid rgba(102, 126, 234, 0.15);
            position: relative;
            z-index: 2;
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

        .register-card h2 {
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

        .form-group {
            margin-bottom: 1.2rem;
        }

        label {
            display: block;
            margin-bottom: 0.4rem;
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

        input, select {
            width: 100%;
            padding: 0.8rem 1rem 0.8rem 2.8rem;
            border: 1.5px solid #e2e8f0;
            border-radius: 1rem;
            font-size: 0.9rem;
            font-family: 'Inter', sans-serif;
            transition: all 0.2s ease;
            background: #fefefe;
            outline: none;
        }

        input:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.15);
        }

        input:focus + i {
            color: #667eea;
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

        .success {
            background: #e8f5e8;
            color: #2e7d32;
            padding: 0.85rem 1rem;
            border-radius: 1rem;
            margin-bottom: 1.5rem;
            border-left: 4px solid #48bb78;
            font-size: 0.85rem;
        }

        .sync-status {
            margin-top: 0.5rem;
            font-size: 0.8rem;
            color: #2d3748;
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
            transition: all 0.25s ease;
            font-family: 'Inter', sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-top: 0.5rem;
        }

        .register-btn:hover {
            background: linear-gradient(95deg, #4338ca, #6d28d9);
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(79, 70, 229, 0.35);
        }

        .login-link {
            text-align: center;
            margin-top: 1.5rem;
            font-size: 0.9rem;
            color: #4a5b6e;
        }

        .login-link a {
            color: #4f46e5;
            text-decoration: none;
            font-weight: 700;
        }

        .login-link a:hover {
            text-decoration: underline;
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
            .register-card {
                padding: 1.8rem 1.4rem;
            }
            .register-card h2 {
                font-size: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="register-card">
        <div class="portal-badge">
            <i class="fas fa-user-plus" style="margin-right: 6px;"></i> New Patient Registration
        </div>
        
        <h2>Create Account</h2>
        <div class="welcome-text">
            Join our Shifa Medical Center to manage appointments, access health records, and receive quality care.
        </div>

        <?php if ($error): ?>
            <div class="error">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="success">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?>
                <?php if ($syncStatus): ?>
                    <div class="sync-status"><?php echo htmlspecialchars($syncStatus, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="form-group">
                <label for="first_name"><i class="fas fa-user"></i> First Name</label>
                <div class="input-wrapper">
                    <i class="fas fa-user"></i>
                    <input id="first_name" type="text" name="first_name" value="<?php echo htmlspecialchars($form['first_name'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="Enter your first name" required>
                </div>
            </div>

            <div class="form-group">
                <label for="last_name"><i class="fas fa-user"></i> Last Name</label>
                <div class="input-wrapper">
                    <i class="fas fa-user"></i>
                    <input id="last_name" type="text" name="last_name" value="<?php echo htmlspecialchars($form['last_name'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="Enter your last name" required>
                </div>
            </div>

            <div class="form-group">
                <label for="email"><i class="fas fa-envelope"></i> Email Address</label>
                <div class="input-wrapper">
                    <i class="fas fa-envelope"></i>
                    <input id="email" type="email" name="email" value="<?php echo htmlspecialchars($form['email'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="patient@example.com" required>
                </div>
            </div>

            <div class="form-group">
                <label for="phone"><i class="fas fa-phone"></i> Phone Number</label>
                <div class="input-wrapper">
                    <i class="fas fa-phone"></i>
                    <input id="phone" type="tel" name="phone" value="<?php echo htmlspecialchars($form['phone'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="0567890123" required>
                </div>
            </div>

            <div class="form-group">
                <label for="date_of_birth"><i class="fas fa-birthday-cake"></i> Date of Birth</label>
                <div class="input-wrapper">
                    <i class="fas fa-calendar"></i>
                    <input id="date_of_birth" type="date" name="date_of_birth" value="<?php echo htmlspecialchars($form['date_of_birth'], ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>
            </div>

            <div class="form-group">
                <label for="password"><i class="fas fa-lock"></i> Password (min 6 characters)</label>
                <div class="input-wrapper">
                    <i class="fas fa-key"></i>
                    <input id="password" type="password" name="password" placeholder="••••••••" required>
                </div>
            </div>

            <div class="form-group">
                <label for="confirm_password"><i class="fas fa-lock"></i> Confirm Password</label>
                <div class="input-wrapper">
                    <i class="fas fa-check-circle"></i>
                    <input id="confirm_password" type="password" name="confirm_password" placeholder="Confirm your password" required>
                </div>
            </div>

            <button type="submit" class="register-btn">
                <i class="fas fa-user-plus"></i> Register Now
            </button>
        </form>

        <div class="login-link">
            <i class="fas fa-sign-in-alt"></i> Already have an account? <a href="login.php">Login here</a>
        </div>

    </div>
</body>
</html> 
