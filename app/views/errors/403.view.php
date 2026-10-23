<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>403 - Access Forbidden | Quack Track</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #333;
        }

        .error-container {
            text-align: center;
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            padding: 40px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            max-width: 600px;
            margin: 20px;
            animation: fadeInUp 0.6s ease-out;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .error-image {
            max-width: 400px;
            width: 100%;
            height: auto;
            margin-bottom: 30px;
            animation: shake 2s ease-in-out infinite;
            border-radius: 10px;
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            10%, 30%, 50%, 70%, 90% { transform: translateX(-5px); }
            20%, 40%, 60%, 80% { transform: translateX(5px); }
        }

        .error-code {
            font-size: 120px;
            font-weight: bold;
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 10px;
            line-height: 1;
        }

        .error-title {
            font-size: 32px;
            color: #2d3748;
            margin-bottom: 20px;
            font-weight: 600;
        }

        .error-message {
            font-size: 18px;
            color: #718096;
            margin-bottom: 30px;
            line-height: 1.6;
        }

        .security-notice {
            background: #fff5f5;
            border-left: 4px solid #fc8181;
            padding: 15px;
            margin: 20px 0;
            text-align: left;
            border-radius: 5px;
        }

        .security-notice-title {
            color: #c53030;
            font-weight: bold;
            margin-bottom: 5px;
        }

        .security-notice-text {
            color: #742a2a;
            font-size: 14px;
        }

        .action-buttons {
            display: flex;
            gap: 15px;
            justify-content: center;
            flex-wrap: wrap;
        }

        .btn {
            display: inline-block;
            padding: 12px 30px;
            text-decoration: none;
            border-radius: 50px;
            font-weight: 600;
            transition: all 0.3s ease;
            font-size: 16px;
        }

        .btn-primary {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(245, 87, 108, 0.4);
        }

        .btn-secondary {
            background: #e2e8f0;
            color: #4a5568;
        }

        .btn-secondary:hover {
            background: #cbd5e0;
            transform: translateY(-2px);
        }

        @media (max-width: 480px) {
            .error-code {
                font-size: 80px;
            }

            .error-title {
                font-size: 24px;
            }

            .error-message {
                font-size: 16px;
            }

            .action-buttons {
                flex-direction: column;
            }

            .btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="error-container">
        <?php if (file_exists('/var/www/assets/403.png')) : ?>
            <img src="/assets/403.png" alt="Access Forbidden" class="error-image">
        <?php endif; ?>

        <div class="error-code">403</div>
        <h1 class="error-title">Access Forbidden</h1>
        <p class="error-message">
            Whoa there! This area is restricted. You don't have permission to access this resource.
        </p>

        <div class="security-notice">
            <div class="security-notice-title">⚠️ Security Notice</div>
            <div class="security-notice-text">
                This attempt has been logged for security purposes. If you believe you should have access to this area, please contact your administrator.
            </div>
        </div>

        <div class="action-buttons">
            <a href="/" class="btn btn-primary">Go Home</a>
            <a href="javascript:history.back()" class="btn btn-secondary">Go Back</a>
        </div>
    </div>
</body>
</html>
