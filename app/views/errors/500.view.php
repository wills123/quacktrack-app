<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>500 - Internal Server Error | Quack Track</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
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
            animation: pulse 2s ease-in-out infinite;
            border-radius: 10px;
        }

        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }

        .error-code {
            font-size: 120px;
            font-weight: bold;
            background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
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

        .technical-info {
            background: #fef5e7;
            border-left: 4px solid #f39c12;
            padding: 15px;
            margin: 20px 0;
            text-align: left;
            border-radius: 5px;
        }

        .technical-info-title {
            color: #d68910;
            font-weight: bold;
            margin-bottom: 5px;
        }

        .technical-info-text {
            color: #7e5109;
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
            background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(250, 112, 154, 0.4);
        }

        .btn-secondary {
            background: #e2e8f0;
            color: #4a5568;
        }

        .btn-secondary:hover {
            background: #cbd5e0;
            transform: translateY(-2px);
        }

        .error-id {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e2e8f0;
            font-size: 12px;
            color: #a0aec0;
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
        <?php if (file_exists('/var/www/assets/500.png')) : ?>
            <img src="/assets/500.png" alt="Server Error" class="error-image">
        <?php endif; ?>

        <div class="error-code">500</div>
        <h1 class="error-title">Internal Server Error</h1>
        <p class="error-message">
            Oops! Something went terribly wrong. Our ducks are working hard to fix this issue. Please try again later.
        </p>

        <div class="technical-info">
            <div class="technical-info-title">🔧 What happened?</div>
            <div class="technical-info-text">
                The server encountered an unexpected condition that prevented it from fulfilling your request. This error has been automatically reported to our technical team.
            </div>
        </div>

        <div class="action-buttons">
            <a href="/" class="btn btn-primary">Go Home</a>
            <a href="javascript:location.reload()" class="btn btn-secondary">Try Again</a>
        </div>

        <?php if (isset($error_id)) : ?>
        <div class="error-id">
            Error ID: <?= htmlspecialchars($error_id) ?>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>
