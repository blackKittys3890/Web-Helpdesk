<?php
session_start();

// Datenbank-Konfiguration
define('DB_HOST', 'localhost');
define('DB_NAME', 'Webseite');
define('DB_USER', 'database');
define('DB_PASS', 'database');

// Datenbank-Verbindung
function getDBConnection() {
    try {
        $pdo = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]
        );
        return $pdo;
    } catch (PDOException $e) {
        http_response_code(500);
        die(json_encode(['error' => 'Database connection failed']));
    }
}

// Request-Methode und Action bestimmen
$method = $_SERVER['REQUEST_METHOD'];
$action = isset($_GET['action']) ? $_GET['action'] : null;

// API Handler - nur wenn Action gesetzt ist
if ($action !== null) {
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');

    // OPTIONS Request für CORS
    if ($method === 'OPTIONS') {
        http_response_code(200);
        exit;
    }

    // POST Requests
    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        
        // Admin Login (ohne Hash)
        if ($action === 'login') {
            $username = $input['username'] ?? '';
            $password = $input['password'] ?? '';
            
            if (empty($username) || empty($password)) {
                http_response_code(400);
                die(json_encode(['error' => 'Username and password required']));
            }
            
            try {
                $pdo = getDBConnection();
                $stmt = $pdo->prepare("SELECT * FROM activity_admins WHERE username = ? AND password = ?");
                $stmt->execute([$username, $password]);
                $admin = $stmt->fetch();
                
                if ($admin) {
                    $_SESSION['admin_id'] = $admin['id'];
                    $_SESSION['admin_username'] = $admin['username'];
                    
                    echo json_encode([
                        'success' => true,
                        'message' => 'Successfully logged in',
                        'admin' => [
                            'id' => $admin['id'],
                            'username' => $admin['username']
                        ]
                    ]);
                } else {
                    http_response_code(401);
                    echo json_encode(['error' => 'Invalid credentials']);
                }
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(['error' => 'Login failed']);
            }
            exit;
        }
        
        // Neue Bewertung speichern
        if ($action === 'submit_rating') {
            $name = trim($input['name'] ?? '');
            $rating = intval($input['rating'] ?? 0);
            $comment = trim($input['comment'] ?? '');
            
            if (empty($name) || empty($comment)) {
                http_response_code(400);
                die(json_encode(['error' => 'Name and comment are required']));
            }
            
            if ($rating < 1 || $rating > 5) {
                http_response_code(400);
                die(json_encode(['error' => 'Rating must be between 1 and 5']));
            }
            
            try {
                $pdo = getDBConnection();
                $stmt = $pdo->prepare("INSERT INTO ratings (name, rating, comment, created_at) VALUES (?, ?, ?, NOW())");
                $stmt->execute([$name, $rating, $comment]);
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Rating successfully saved',
                    'id' => $pdo->lastInsertId()
                ]);
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(['error' => 'Rating could not be saved']);
            }
            exit;
        }
        
        // Admin Logout
        if ($action === 'logout') {
            session_destroy();
            echo json_encode(['success' => true, 'message' => 'Successfully logged out']);
            exit;
        }
    }

    // GET Requests
    if ($method === 'GET') {
        
        // Bewertungen abrufen (nur für Admins)
        if ($action === 'get_ratings') {
            if (!isset($_SESSION['admin_id'])) {
                http_response_code(401);
                die(json_encode(['error' => 'Not authorized']));
            }
            
            try {
                $pdo = getDBConnection();
                $stmt = $pdo->query("SELECT * FROM ratings ORDER BY created_at DESC");
                $ratings = $stmt->fetchAll();
                
                echo json_encode([
                    'success' => true,
                    'ratings' => $ratings
                ]);
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(['error' => 'Ratings could not be loaded']);
            }
            exit;
        }
        
        // Durchschnittsbewertung und Anzahl abrufen (öffentlich)
        if ($action === 'get_stats') {
            try {
                $pdo = getDBConnection();
                $stmt = $pdo->query("SELECT COUNT(*) as total, AVG(rating) as average FROM ratings");
                $stats = $stmt->fetch();
                
                echo json_encode([
                    'success' => true,
                    'total' => intval($stats['total']),
                    'average' => round(floatval($stats['average']), 1)
                ]);
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(['error' => 'Stats could not be loaded']);
            }
            exit;
        }
        
        // Admin Session prüfen
        if ($action === 'check_session') {
            if (isset($_SESSION['admin_id'])) {
                echo json_encode([
                    'success' => true,
                    'isAdmin' => true,
                    'admin' => [
                        'id' => $_SESSION['admin_id'],
                        'username' => $_SESSION['admin_username']
                    ]
                ]);
            } else {
                echo json_encode([
                    'success' => true,
                    'isAdmin' => false
                ]);
            }
            exit;
        }
    }

    http_response_code(400);
    echo json_encode(['error' => 'Invalid request']);
    exit;
}

// Ab hier: HTML Frontend (nur wenn keine Action)
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>⭐ Rate Rootware ⭐</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #121212;
            color: #e0e0e0;
            min-height: 100vh;
            padding: 0;
            margin: 0;
        }
		
        /* Back to Home Button - wie auf team.html */
        .back-link-top {
            position: fixed;
            top: 20px;
            left: 20px;
            z-index: 1000;
        }

        .back-link-top a {
            display: inline-block;
            padding: 10px 20px;
            background-color: #00ffcc;
            color: #121212;
            text-decoration: none;
            border-radius: 5px;
            font-weight: bold;
            transition: opacity 0.3s ease;
        }

        .back-link-top a:hover {
            opacity: 0.8;
        }

        .container { max-width: 1200px; margin: 0 auto; padding: 40px 20px; }
        .hero { text-align: center; margin-bottom: 60px; }
        .title {
            font-size: 3.5rem;
            margin-bottom: 20px;
            color: #ffffff;
            text-shadow: 0 0 20px rgba(255, 255, 255, 0.1);
        }
        .highlight {
            background: linear-gradient(135deg, #00ffcc, #00cc99);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .tagline {
            font-size: 1.3rem;
            color: #b0b0b0;
            margin-bottom: 3rem;
            line-height: 1.6;
        }
        .stats {
            display: flex;
            gap: 2rem;
            justify-content: center;
            flex-wrap: wrap;
        }
        .stat-box {
            padding: 20px 40px;
            background: linear-gradient(145deg, #1a1a1a, #0f0f0f);
            border: 2px solid #333;
            border-radius: 15px;
            min-width: 150px;
        }
        .stat-number {
            font-size: 2.5rem;
            color: #00ffcc;
            font-weight: bold;
            margin-bottom: 5px;
        }
        .stat-label { font-size: 1rem; color: #888; }
        .message {
            padding: 15px 25px;
            border-radius: 10px;
            margin-bottom: 20px;
            text-align: center;
            font-weight: 600;
            display: none;
        }
        .success { background: rgba(0, 255, 204, 0.1); border: 2px solid #00ffcc; color: #00ffcc; }
        .error { background: rgba(255, 100, 100, 0.1); border: 2px solid #ff6464; color: #ff6464; }
        .admin-section { text-align: right; margin-bottom: 30px; }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 12px 25px;
            background: transparent;
            border: 2px solid #404040;
            color: #e0e0e0;
            border-radius: 10px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .btn:hover { border-color: #00ffcc; color: #00ffcc; }
        .btn-logout { border-color: #ff6464; color: #ff6464; }
        .box {
            padding: 40px;
            background: linear-gradient(145deg, #1a1a1a, #0f0f0f);
            border: 2px solid #333;
            border-radius: 20px;
            margin-bottom: 40px;
        }
        .form-group { margin-bottom: 25px; }
        .label {
            display: block;
            font-size: 1.1rem;
            color: #b0b0b0;
            margin-bottom: 10px;
            font-weight: 600;
        }
        input, textarea {
            width: 100%;
            padding: 15px;
            background: #0a0a0a;
            border: 2px solid #333;
            border-radius: 10px;
            color: #e0e0e0;
            font-size: 1rem;
            outline: none;
            transition: border-color 0.3s ease;
        }
        input:focus, textarea:focus { border-color: #00ffcc; }
        textarea { resize: vertical; font-family: inherit; }
        .stars { display: flex; gap: 10px; }
        .star {
            font-size: 32px;
            cursor: pointer;
            color: #666;
            transition: all 0.2s ease;
        }
        .star.active { color: #00ffcc; }
        .btn-submit {
            width: 100%;
            padding: 18px;
            background: #00ffcc;
            color: #121212;
            border: none;
            border-radius: 30px;
            font-size: 1.1rem;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.4s ease;
        }
        .btn-submit:hover {
            background: #00e6b8;
            transform: translateY(-3px);
            box-shadow: 0 10px 35px rgba(0, 255, 204, 0.5);
        }
        .ratings-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 25px;
        }
        .rating-card {
            padding: 25px;
            background: linear-gradient(145deg, #1a1a1a, #0f0f0f);
            border: 2px solid #333;
            border-radius: 15px;
            transition: all 0.3s ease;
        }
        .rating-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.4);
        }
        .rating-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        .rating-user {
            font-size: 1.1rem;
            font-weight: 600;
            color: #ffffff;
        }
        .rating-stars { display: flex; gap: 3px; }
        .rating-comment {
            font-size: 1rem;
            color: #b0b0b0;
            line-height: 1.6;
            margin-bottom: 15px;
        }
        .rating-date {
            font-size: 0.85rem;
            color: #666;
            text-align: right;
        }
        .hidden { display: none; }
        .section-title {
            font-size: 2rem;
            color: #ffffff;
            margin-bottom: 30px;
            text-align: center;
        }
        .accent { color: #00ffcc; }
        footer {
            margin-top: 80px;
            padding-top: 30px;
            border-top: 1px solid #333;
            text-align: center;
        }
        .footer-links { margin-bottom: 20px; }
        .footer-links a {
            color: #888;
            text-decoration: none;
            margin: 0 20px;
            opacity: 0.7;
            transition: all 0.3s ease;
        }
        .footer-links a:hover { opacity: 1; color: #00ffcc; }
    </style>
</head>
<body>
    <!-- Back to Home Button - wie auf team.html -->
    <div class="back-link-top">
        <a href="/index.html">← Back to Home</a>
    </div>

    <div class="container">
        <div class="hero">
            <h1 class="title">⭐ <span class="highlight">Rate Rootware</span> ⭐</h1>
            <p class="tagline">Your opinion matters! Share your experience with our community.</p>
            <div class="stats">
                <div class="stat-box">
                    <div class="stat-number" id="avg-rating">0.0</div>
                    <div class="stat-label">Average</div>
                </div>
                <div class="stat-box">
                    <div class="stat-number" id="total-ratings">0</div>
                    <div class="stat-label">Ratings</div>
                </div>
            </div>
        </div>

        <div id="success-msg" class="message success"></div>
        <div id="error-msg" class="message error"></div>

        <div class="admin-section">
            <button id="admin-btn" class="btn" onclick="toggleLogin()">🔒 Admin Login</button>
            <button id="logout-btn" class="btn btn-logout hidden" onclick="logout()">🚪 Logout</button>
        </div>

        <div id="login-box" class="box hidden">
            <h3 class="section-title">Admin Login</h3>
            <div class="form-group">
                <input type="text" id="login-username" placeholder="Username">
            </div>
            <div class="form-group">
                <input type="password" id="login-password" placeholder="Password">
            </div>
            <button class="btn-submit" onclick="login()">Login</button>
        </div>

        <div class="box">
            <h2 class="section-title"><span class="accent">Submit a Rating</span></h2>
            <div class="form-group">
                <label class="label">Your Name</label>
                <input type="text" id="rating-name" placeholder="John Doe">
            </div>
            <div class="form-group">
                <label class="label">Rating</label>
                <div class="stars" id="star-rating">
                    <span class="star active" data-rating="1">★</span>
                    <span class="star active" data-rating="2">★</span>
                    <span class="star active" data-rating="3">★</span>
                    <span class="star active" data-rating="4">★</span>
                    <span class="star active" data-rating="5">★</span>
                </div>
            </div>
            <div class="form-group">
                <label class="label">Your Comment</label>
                <textarea id="rating-comment" rows="4" placeholder="Share your experience with us..."></textarea>
            </div>
            <button class="btn-submit" onclick="submitRating()">📤 Submit Rating</button>
        </div>

        <div id="ratings-section" class="hidden">
            <h2 class="section-title"><span class="accent">All Ratings</span> (<span id="ratings-count">0</span>)</h2>
            <div id="ratings-list" class="ratings-grid"></div>
        </div>
		
        <footer>
            <div class="footer-links">
                <a href="/imprint.html">Imprint</a>
                <a href="/privacy.html">Privacy Policy</a>
            </div>
            <div style="color: #666; font-size: 0.9rem;">© 2025 Rootware - All rights reserved</div>
        </footer>
    </div>

    <script>
        let currentRating = 5;
        let isAdmin = false;

        document.querySelectorAll('.star').forEach(star => {
            star.addEventListener('click', function() {
                currentRating = parseInt(this.dataset.rating);
                updateStars();
            });
        });

        function updateStars() {
            document.querySelectorAll('.star').forEach(star => {
                if (parseInt(star.dataset.rating) <= currentRating) {
                    star.classList.add('active');
                } else {
                    star.classList.remove('active');
                }
            });
        }

        function showMessage(msg, type) {
            const el = document.getElementById(type + '-msg');
            el.textContent = msg;
            el.style.display = 'block';
            setTimeout(() => el.style.display = 'none', 3000);
        }

        function toggleLogin() {
            const box = document.getElementById('login-box');
            box.classList.toggle('hidden');
        }

        async function checkSession() {
            const res = await fetch('?action=check_session');
            const data = await res.json();
            if (data.isAdmin) {
                isAdmin = true;
                document.getElementById('admin-btn').classList.add('hidden');
                document.getElementById('logout-btn').classList.remove('hidden');
                loadRatings();
            }
        }

        async function loadStats() {
            const res = await fetch('?action=get_stats');
            const data = await res.json();
            if (data.success) {
                document.getElementById('avg-rating').textContent = data.average || '0.0';
                document.getElementById('total-ratings').textContent = data.total;
            }
        }

        async function login() {
            const username = document.getElementById('login-username').value;
            const password = document.getElementById('login-password').value;
            
            const res = await fetch('?action=login', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({username, password})
            });
            
            const data = await res.json();
            if (res.ok && data.success) {
                showMessage('Successfully logged in!', 'success');
                isAdmin = true;
                document.getElementById('login-box').classList.add('hidden');
                document.getElementById('admin-btn').classList.add('hidden');
                document.getElementById('logout-btn').classList.remove('hidden');
                loadRatings();
            } else {
                showMessage(data.error || 'Login failed', 'error');
            }
        }

        async function logout() {
            await fetch('?action=logout', {method: 'POST'});
            showMessage('Successfully logged out!', 'success');
            isAdmin = false;
            document.getElementById('admin-btn').classList.remove('hidden');
            document.getElementById('logout-btn').classList.add('hidden');
            document.getElementById('ratings-section').classList.add('hidden');
        }

        async function submitRating() {
            const name = document.getElementById('rating-name').value.trim();
            const comment = document.getElementById('rating-comment').value.trim();
            
            if (!name || !comment) {
                showMessage('Please fill in all fields', 'error');
                return;
            }
            
            const res = await fetch('?action=submit_rating', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({name, rating: currentRating, comment})
            });
            
            const data = await res.json();
            if (res.ok && data.success) {
                showMessage('Rating successfully submitted!', 'success');
                document.getElementById('rating-name').value = '';
                document.getElementById('rating-comment').value = '';
                currentRating = 5;
                updateStars();
                loadStats();
                if (isAdmin) loadRatings();
            } else {
                showMessage(data.error || 'Error saving rating', 'error');
            }
        }

        async function loadRatings() {
            const res = await fetch('?action=get_ratings');
            const data = await res.json();
            if (data.success) {
                document.getElementById('ratings-section').classList.remove('hidden');
                document.getElementById('ratings-count').textContent = data.ratings.length;
                
                const list = document.getElementById('ratings-list');
                list.innerHTML = data.ratings.map(r => `
                    <div class="rating-card">
                        <div class="rating-header">
                            <div class="rating-user">👤 ${r.name}</div>
                            <div class="rating-stars">${'★'.repeat(r.rating)}</div>
                        </div>
                        <p class="rating-comment">${r.comment}</p>
                        <div class="rating-date">${new Date(r.created_at).toLocaleDateString('en-US')}</div>
                    </div>
                `).join('');
            }
        }

        checkSession();
        loadStats();
    </script>
</body>
</html>