<?php
// Backend API Handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['api_action'])) {
    header('Content-Type: application/json');
    
    // Datenbank-Konfiguration
    define('DB_HOST', 'localhost');
    define('DB_NAME', 'Webseite');
    define('DB_USER', 'database');
    define('DB_PASS', 'database');
    
    function getDB() {
        try {
            $pdo = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
            );
            return $pdo;
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Datenbankverbindung fehlgeschlagen']);
            exit;
        }
    }
    
    $action = $_POST['api_action'];
    
    if ($action === 'login') {
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');
        
        if (empty($username) || empty($password)) {
            echo json_encode(['success' => false, 'message' => 'Benutzername und Passwort erforderlich']);
            exit;
        }
        
        $db = getDB();
        $stmt = $db->prepare("SELECT * FROM activity_admins WHERE LOWER(username) = LOWER(:username) LIMIT 1");
        $stmt->execute(['username' => $username]);
        $user = $stmt->fetch();
        
        if ($user && strtolower($user['password']) === strtolower($password)) {
            $sessionToken = bin2hex(random_bytes(32));
            $expiresAt = date('Y-m-d H:i:s', strtotime('+24 hours'));
            
            $stmt = $db->prepare("INSERT INTO activity_sessions (user_id, session_token, expires_at) VALUES (:user_id, :token, :expires)");
            $stmt->execute(['user_id' => $user['id'], 'token' => $sessionToken, 'expires' => $expiresAt]);
            
            $db->prepare("DELETE FROM activity_sessions WHERE expires_at < NOW()")->execute();
            
            echo json_encode(['success' => true, 'message' => 'Login erfolgreich', 'username' => $user['username'], 'token' => $sessionToken]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Ungültiger Benutzername oder Passwort']);
        }
        exit;
    }
    
    if ($action === 'checkSession') {
        $token = $_POST['token'] ?? '';
        
        if (empty($token)) {
            echo json_encode(['success' => false, 'message' => 'Kein Token vorhanden']);
            exit;
        }
        
        $db = getDB();
        $stmt = $db->prepare("SELECT s.*, a.username FROM activity_sessions s JOIN activity_admins a ON s.user_id = a.id WHERE s.session_token = :token AND s.expires_at > NOW()");
        $stmt->execute(['token' => $token]);
        $session = $stmt->fetch();
        
        if ($session) {
            echo json_encode(['success' => true, 'username' => $session['username']]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Ungültige oder abgelaufene Session']);
        }
        exit;
    }
    
    if ($action === 'logout') {
        $token = $_POST['token'] ?? '';
        
        if (!empty($token)) {
            $db = getDB();
            $stmt = $db->prepare("DELETE FROM activity_sessions WHERE session_token = :token");
            $stmt->execute(['token' => $token]);
        }
        
        echo json_encode(['success' => true, 'message' => 'Logout erfolgreich']);
        exit;
    }
    
    if ($action === 'sendToDiscord') {
        $message = $_POST['message'] ?? '';
        $webhookUrl = 'https://discord.com/api/webhooks/1429836485338730640/b1a6hvVUXPNavt8nabpwyHUp5lAxnuOtzEJ-PpLR8HFShqSEIfKfr8sa2iJt3RuVLX-Y';
        
        if (empty($message)) {
            echo json_encode(['success' => false, 'message' => 'Keine Nachricht vorhanden']);
            exit;
        }
        
        $data = json_encode(['content' => $message]);
        
        $ch = curl_init($webhookUrl);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode >= 200 && $httpCode < 300) {
            echo json_encode(['success' => true, 'message' => 'Nachricht an Discord gesendet!']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Fehler beim Senden an Discord']);
        }
        exit;
    }
    
    echo json_encode(['success' => false, 'message' => 'Ungültige Aktion']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Wochenplan Automatisch</title>
<style>
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }
    
    body { 
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
        padding: 30px 20px; 
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        min-height: 100vh;
    }
    
    .login-screen {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        display: flex;
        justify-content: center;
        align-items: center;
        z-index: 1000;
    }
    
    .login-screen.hidden {
        display: none;
    }
    
    .login-box {
        background: white;
        padding: 40px;
        border-radius: 20px;
        box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        max-width: 400px;
        width: 90%;
        animation: slideIn 0.5s ease;
    }
    
    @keyframes slideIn {
        from {
            opacity: 0;
            transform: translateY(-30px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    
    .login-box h2 {
        text-align: center;
        color: #333;
        margin-bottom: 30px;
        font-size: 2em;
    }
    
    .login-form {
        display: flex;
        flex-direction: column;
        gap: 20px;
    }
    
    .form-group {
        display: flex;
        flex-direction: column;
        gap: 8px;
    }
    
    .form-group label {
        font-weight: 600;
        color: #495057;
        font-size: 14px;
    }
    
    .form-group input {
        padding: 12px 15px;
        border: 2px solid #dee2e6;
        border-radius: 8px;
        font-size: 16px;
        transition: all 0.3s ease;
    }
    
    .form-group input:focus {
        outline: none;
        border-color: #667eea;
        box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
    }
    
    .login-btn {
        padding: 15px;
        background: linear-gradient(145deg, #667eea, #764ba2);
        color: white;
        border: none;
        border-radius: 10px;
        font-size: 16px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
        text-transform: uppercase;
        letter-spacing: 1px;
        margin-top: 10px;
    }
    
    .login-btn:hover {
        background: linear-gradient(145deg, #764ba2, #667eea);
        transform: translateY(-2px);
        box-shadow: 0 8px 20px rgba(102, 126, 234, 0.4);
    }
    
    .login-btn:disabled {
        opacity: 0.6;
        cursor: not-allowed;
    }
    
    .error-message {
        color: #dc3545;
        text-align: center;
        font-size: 14px;
        margin-top: 10px;
        display: none;
        font-weight: 500;
    }
    
    .error-message.show {
        display: block;
        animation: shake 0.5s;
    }
    
    @keyframes shake {
        0%, 100% { transform: translateX(0); }
        25% { transform: translateX(-10px); }
        75% { transform: translateX(10px); }
    }
    
    .main-container {
        max-width: 1200px;
        margin: 0 auto;
        background: rgba(255, 255, 255, 0.95);
        border-radius: 20px;
        padding: 30px;
        box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
        backdrop-filter: blur(10px);
        display: none;
    }
    
    .main-container.show {
        display: block;
        animation: fadeIn 0.5s ease;
    }
    
    @keyframes fadeIn {
        from { opacity: 0; }
        to { opacity: 1; }
    }
    
    .header-controls {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 30px;
        flex-wrap: wrap;
        gap: 20px;
    }
    
    .header-left {
        display: flex;
        gap: 15px;
        align-items: center;
    }
    
    h1 { 
        color: #333;
        font-size: 2.5em;
        font-weight: 300;
        text-shadow: 0 2px 4px rgba(0,0,0,0.1);
        margin: 0;
        flex: 1;
        text-align: center;
    }
    
    .admin-info {
        background: linear-gradient(145deg, #e9ecef, #f8f9fa);
        padding: 8px 15px;
        border-radius: 8px;
        font-size: 14px;
        color: #495057;
        font-weight: 500;
        border: 2px solid #dee2e6;
    }
    
    .lang-btn {
        padding: 10px 20px;
        background: linear-gradient(145deg, #6c757d, #495057);
        color: white;
        border: none;
        border-radius: 8px;
        cursor: pointer;
        font-weight: 500;
        transition: all 0.3s ease;
        font-size: 14px;
        white-space: nowrap;
    }
    
    .lang-btn:hover {
        background: linear-gradient(145deg, #495057, #343a40);
        transform: scale(1.05);
    }
    
    .logout-btn {
        padding: 10px 20px;
        background: linear-gradient(145deg, #dc3545, #c82333);
        color: white;
        border: none;
        border-radius: 8px;
        cursor: pointer;
        font-weight: 500;
        transition: all 0.3s ease;
        font-size: 14px;
        white-space: nowrap;
    }
    
    .logout-btn:hover {
        background: linear-gradient(145deg, #c82333, #bd2130);
        transform: scale(1.05);
    }
    
    .week-selector {
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 15px;
        margin-bottom: 30px;
        padding: 20px;
        background: #f8f9fa;
        border-radius: 12px;
        border: 2px solid #e9ecef;
    }
    
    .week-selector label {
        font-weight: 600;
        color: #495057;
    }
    
    .week-selector input {
        padding: 8px 12px;
        border: 2px solid #dee2e6;
        border-radius: 6px;
        font-size: 14px;
        transition: border-color 0.3s ease;
    }
    
    .week-selector input:focus {
        outline: none;
        border-color: #667eea;
        box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
    }
    
    .container { 
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 25px; 
        margin-bottom: 30px;
    }
    
    .card { 
        background: linear-gradient(145deg, #ffffff, #f8f9fa);
        padding: 25px; 
        border-radius: 16px; 
        box-shadow: 0 8px 25px rgba(0,0,0,0.08);
        border: 1px solid rgba(255,255,255,0.2);
        transition: transform 0.3s ease, box-shadow 0.3s ease;
    }
    
    .card:hover {
        transform: translateY(-5px);
        box-shadow: 0 15px 35px rgba(0,0,0,0.12);
    }
    
    .card h3 {
        text-align: center;
        margin-bottom: 20px;
        color: #495057;
        font-size: 1.4em;
        font-weight: 600;
        padding-bottom: 10px;
        border-bottom: 2px solid #e9ecef;
    }
    
    .day-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 12px;
        padding: 8px;
        border-radius: 8px;
        transition: background-color 0.2s ease;
    }
    
    .day-row:hover {
        background-color: #f8f9fa;
    }
    
    .day-label {
        font-weight: 500;
        color: #6c757d;
        min-width: 80px;
    }
    
    .day-input {
        width: 70px;
        padding: 6px 10px;
        border: 2px solid #dee2e6;
        border-radius: 6px;
        text-align: center;
        font-weight: 500;
        transition: all 0.3s ease;
    }
    
    .day-input:focus {
        outline: none;
        border-color: #667eea;
        box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
    }
    
    .absence-btn {
        padding: 6px 12px;
        background: linear-gradient(145deg, #fd7e14, #e8590c);
        color: white;
        border: none;
        border-radius: 6px;
        cursor: pointer;
        font-size: 12px;
        font-weight: 500;
        transition: all 0.3s ease;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    .absence-btn:hover {
        background: linear-gradient(145deg, #e8590c, #dc5200);
        transform: scale(1.05);
    }
    
    .generate-btn {
        display: block;
        margin: 0 auto 30px;
        padding: 15px 40px;
        background: linear-gradient(145deg, #667eea, #764ba2);
        color: white;
        border: none;
        border-radius: 12px;
        font-size: 16px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
        text-transform: uppercase;
        letter-spacing: 1px;
        box-shadow: 0 8px 20px rgba(102, 126, 234, 0.3);
    }
    
    .generate-btn:hover {
        background: linear-gradient(145deg, #764ba2, #667eea);
        transform: translateY(-2px);
        box-shadow: 0 12px 25px rgba(102, 126, 234, 0.4);
    }
    
    .discord-btn {
        display: block;
        margin: 0 auto 30px;
        padding: 15px 40px;
        background: linear-gradient(145deg, #5865F2, #4752C4);
        color: white;
        border: none;
        border-radius: 12px;
        font-size: 16px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
        text-transform: uppercase;
        letter-spacing: 1px;
        box-shadow: 0 8px 20px rgba(88, 101, 242, 0.3);
    }
    
    .discord-btn:hover {
        background: linear-gradient(145deg, #4752C4, #3C45A5);
        transform: translateY(-2px);
        box-shadow: 0 12px 25px rgba(88, 101, 242, 0.4);
    }
    
    .discord-btn:disabled {
        opacity: 0.6;
        cursor: not-allowed;
    }
    
    .output-container {
        position: relative;
        margin-top: 20px;
    }
    
    .output-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 15px;
    }
    
    .output-title {
        font-size: 1.2em;
        font-weight: 600;
        color: #495057;
    }
    
    .copy-btn {
        padding: 10px 20px;
        background: linear-gradient(145deg, #28a745, #20c997);
        color: white;
        border: none;
        border-radius: 8px;
        cursor: pointer;
        font-weight: 500;
        transition: all 0.3s ease;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .copy-btn:hover {
        background: linear-gradient(145deg, #20c997, #17a2b8);
        transform: scale(1.05);
    }
    
    .copy-btn.copied {
        background: linear-gradient(145deg, #17a2b8, #138496);
    }
    
    textarea { 
        width: 100%; 
        height: 400px; 
        padding: 20px; 
        border: 2px solid #dee2e6;
        border-radius: 12px;
        font-family: 'Courier New', monospace;
        font-size: 14px;
        line-height: 1.5;
        resize: vertical;
        background: #f8f9fa;
        color: #495057;
        transition: border-color 0.3s ease;
    }
    
    textarea:focus {
        outline: none;
        border-color: #667eea;
        box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
    }
    
    .legend {
        display: flex;
        justify-content: center;
        gap: 30px;
        margin-bottom: 30px;
        padding: 20px;
        background: rgba(248, 249, 250, 0.8);
        border-radius: 12px;
        flex-wrap: wrap;
    }
    
    .legend-item {
        display: flex;
        align-items: center;
        gap: 8px;
        font-weight: 500;
        color: #495057;
    }
    
    @media (max-width: 768px) {
        .container {
            grid-template-columns: 1fr;
        }
        
        .week-selector {
            flex-direction: column;
            gap: 10px;
        }
        
        .legend {
            flex-direction: column;
            align-items: center;
            gap: 15px;
        }
        
        .header-controls {
            flex-direction: column;
            text-align: center;
        }
        
        .header-controls h1 {
            order: 1;
        }
        
        .header-left {
            order: 2;
            flex-direction: column;
        }
        
        .login-box {
            padding: 30px 20px;
        }
    }
</style>
</head>
<body>

<!-- Login Screen -->
<div class="login-screen" id="loginScreen">
    <div class="login-box">
        <h2>🔐 Admin Login</h2>
        <form class="login-form" onsubmit="handleLogin(event)">
            <div class="form-group">
                <label for="adminName">Admin Name:</label>
                <input type="text" id="adminName" required autocomplete="username">
            </div>
            <div class="form-group">
                <label for="adminPassword">Passwort:</label>
                <input type="password" id="adminPassword" required autocomplete="current-password">
            </div>
            <button type="submit" class="login-btn" id="loginBtn">Einloggen</button>
            <div class="error-message" id="errorMessage">
                ❌ Ungültiger Name oder Passwort!
            </div>
        </form>
    </div>
</div>

<!-- Main Content -->
<div class="main-container" id="mainContainer">
    <div class="header-controls">
        <div class="header-left">
            <div class="admin-info" id="adminInfo">👤 Admin</div>
            <button class="logout-btn" onclick="handleLogout()">🚪 Logout</button>
        </div>
        <h1 id="mainTitle">🗓️ Weekly Plan Generator</h1>
        <button class="lang-btn" id="langBtn" onclick="toggleLanguage()">🇩🇪 Deutsch</button>
    </div>
    
    <div class="week-selector">
        <label for="weekStart">Woche vom:</label>
        <input type="date" id="weekStart" onchange="updateWeekEnd()">
        <span>bis</span>
        <input type="date" id="weekEnd" readonly>
    </div>
    
    <div class="legend">
        <div class="legend-item">
            <span>🟢</span>
            <span>Tagesziel erreicht</span>
        </div>
        <div class="legend-item">
            <span>🟠</span>
            <span>Abwesend</span>
        </div>
        <div class="legend-item">
            <span>🔴</span>
            <span>Tagesziel nicht erreicht</span>
        </div>
    </div>
    
    <div class="container" id="cards"></div>
    
    <button class="generate-btn" onclick="generateMarkdown()">
        📊 Wochenplan Generieren
    </button>
    
    <button class="discord-btn" id="discordBtn" onclick="sendToDiscord()">
        📤 An Discord senden
    </button>
    
    <div class="output-container">
        <div class="output-header">
            <div class="output-title">Generierter Markdown:</div>
            <button class="copy-btn" id="copyBtn" onclick="copyToClipboard()">
                📋 Kopieren
            </button>
        </div>
        <textarea id="output" readonly placeholder="Hier erscheint der generierte Wochenplan..."></textarea>
    </div>
</div>

<script>
const names = ["Kittys","Filzstift","Luca"];
let currentLanguage = 'en';
let sessionToken = '';
let currentAdmin = '';

const translations = {
    en: {
        title: '🗓️ Weekly Plan Generator',
        langBtn: '🇩🇪 Deutsch',
        weekFrom: 'Week from:',
        to: 'to',
        goalReached: 'Reached daily goal',
        absent: 'Absent',
        goalNotReached: 'Didn\'t reach daily goal',
        days: ["Monday","Tuesday","Wednesday","Thursday","Friday","Saturday","Sunday"],
        absentBtn: 'Absent',
        generateBtn: '📊 Generate Weekly Plan',
        discordBtn: '📤 Send to Discord',
        discordSending: '⏳ Sending...',
        discordSuccess: '✅ Sent to Discord!',
        discordError: '❌ Error sending to Discord',
        outputTitle: 'Generated Markdown:',
        copyBtn: '📋 Copy',
        copied: '✅ Copied!',
        placeholder: 'Generated weekly plan will appear here...',
        selectWeek: 'Please select a week date!',
        generateFirst: 'Please generate a weekly plan first!',
        legendTexts: {
            green: 'Did reach daily minimum activity',
            orange: 'In Absence',
            red: 'Didn\'t reach daily minimum activity'
        },
        logout: '🚪 Logout',
        adminInfo: '👤 Admin:'
    },
    de: {
        title: '🗓️ Wochenplan Generator',
        langBtn: '🇺🇸 English',
        weekFrom: 'Woche vom:',
        to: 'bis',
        goalReached: 'Tagesziel erreicht',
        absent: 'Abwesend',
        goalNotReached: 'Tagesziel nicht erreicht',
        days: ["Montag","Dienstag","Mittwoch","Donnerstag","Freitag","Samstag","Sonntag"],
        absentBtn: 'Abwesend',
        generateBtn: '📊 Wochenplan Generieren',
        discordBtn: '📤 An Discord senden',
        discordSending: '⏳ Wird gesendet...',
        discordSuccess: '✅ An Discord gesendet!',
        discordError: '❌ Fehler beim Senden',
        outputTitle: 'Generierter Markdown:',
        copyBtn: '📋 Kopieren',
        copied: '✅ Kopiert!',
        placeholder: 'Hier erscheint der generierte Wochenplan...',
        selectWeek: 'Bitte wählen Sie ein Wochendatum aus!',
        generateFirst: 'Bitte generieren Sie zuerst einen Wochenplan!',
        legendTexts: {
            green: 'Tagesziel erreicht',
            orange: 'Abwesend',
            red: 'Tagesziel nicht erreicht'
        },
        logout: '🚪 Logout',
        adminInfo: '👤 Admin:'
    }
};

async function apiCall(action, data = {}) {
    try {
        const formData = new FormData();
        formData.append('api_action', action);
        for (let key in data) {
            formData.append(key, data[key]);
        }
        
        const response = await fetch('', {
            method: 'POST',
            body: formData
        });
        return await response.json();
    } catch (error) {
        console.error('API Error:', error);
        return { success: false, message: 'Verbindungsfehler zum Server' };
    }
}

async function handleLogin(event) {
    event.preventDefault();
    const username = document.getElementById('adminName').value;
    const password = document.getElementById('adminPassword').value;
    const errorMsg = document.getElementById('errorMessage');
    const loginBtn = document.getElementById('loginBtn');
    
    loginBtn.disabled = true;
    loginBtn.textContent = 'Wird eingeloggt...';
    
    const result = await apiCall('login', { username, password });
    
    if (result.success) {
        sessionToken = result.token;
        currentAdmin = result.username;
        
        localStorage.setItem('sessionToken', sessionToken);
        localStorage.setItem('adminName', currentAdmin);
        
        showMainContent();
        errorMsg.classList.remove('show');
    } else {
        errorMsg.textContent = '❌ ' + result.message;
        errorMsg.classList.add('show');
        document.getElementById('adminPassword').value = '';
    }
    
    loginBtn.disabled = false;
    loginBtn.textContent = 'Einloggen';
}

async function handleLogout() {
    await apiCall('logout', { token: sessionToken });
    
    sessionToken = '';
    currentAdmin = '';
    localStorage.removeItem('sessionToken');
    localStorage.removeItem('adminName');
    
    showLoginScreen();
}

function showMainContent() {
    document.getElementById('loginScreen').classList.add('hidden');
    document.getElementById('mainContainer').classList.add('show');
    const t = translations[currentLanguage];
    document.getElementById('adminInfo').textContent = `${t.adminInfo} ${currentAdmin}`;
}

function showLoginScreen() {
    document.getElementById('loginScreen').classList.remove('hidden');
    document.getElementById('mainContainer').classList.remove('show');
    document.getElementById('adminName').value = '';
    document.getElementById('adminPassword').value = '';
}

async function checkLoginStatus() {
    const savedToken = localStorage.getItem('sessionToken');
    const savedAdmin = localStorage.getItem('adminName');
    
    if (savedToken && savedAdmin) {
        const result = await apiCall('checkSession', { token: savedToken });
        
        if (result.success) {
            sessionToken = savedToken;
            currentAdmin = result.username;
            showMainContent();
        } else {
            localStorage.removeItem('sessionToken');
            localStorage.removeItem('adminName');
            showLoginScreen();
        }
    } else {
        showLoginScreen();
    }
}

function toggleLanguage() {
    currentLanguage = currentLanguage === 'en' ? 'de' : 'en';
    updateLanguage();
}

function updateLanguage() {
    const t = translations[currentLanguage];
    
    document.getElementById('mainTitle').textContent = t.title;
    document.getElementById('langBtn').textContent = t.langBtn;
    document.querySelector('label[for="weekStart"]').textContent = t.weekFrom;
    document.querySelector('.week-selector span').textContent = t.to;
    document.querySelector('.generate-btn').innerHTML = t.generateBtn;
    document.getElementById('discordBtn').innerHTML = t.discordBtn;
    document.querySelector('.output-title').textContent = t.outputTitle;
    document.getElementById('copyBtn').textContent = t.copyBtn;
    document.getElementById('output').placeholder = t.placeholder;
    document.querySelector('.logout-btn').textContent = t.logout;
    document.getElementById('adminInfo').textContent = `${t.adminInfo} ${currentAdmin}`;
    
    const legendItems = document.querySelectorAll('.legend-item span:last-child');
    legendItems[0].textContent = t.goalReached;
    legendItems[1].textContent = t.absent;
    legendItems[2].textContent = t.goalNotReached;
    
    createCards();
}

function initializeWeek() {
    const today = new Date();
    const monday = new Date(today);
    monday.setDate(today.getDate() - today.getDay() + 1);
    
    const weekStart = document.getElementById('weekStart');
    weekStart.value = monday.toISOString().split('T')[0];
    updateWeekEnd();
}

function updateWeekEnd() {
    const weekStart = document.getElementById('weekStart');
    const weekEnd = document.getElementById('weekEnd');
    
    if (weekStart.value) {
        const startDate = new Date(weekStart.value);
        const endDate = new Date(startDate);
        endDate.setDate(startDate.getDate() + 6);
        weekEnd.value = endDate.toISOString().split('T')[0];
    }
}

function createCards() {
    const container = document.getElementById('cards');
    container.innerHTML = '';
    const t = translations[currentLanguage];
    
    names.forEach(name => {
        const card = document.createElement('div');
        card.className = 'card';
        card.innerHTML = `<h3>${name}</h3>`;
        
        t.days.forEach((day, index) => {
            const dayRow = document.createElement('div');
            dayRow.className = 'day-row';
            dayRow.innerHTML = `
                <span class="day-label">${day}:</span>
                <input type="number" min="0" value="0" class="day-input" data-day="${day}" data-day-index="${index}">
                <button type="button" class="absence-btn" onclick="setAbsence(this)">${t.absentBtn}</button>
            `;
            card.appendChild(dayRow);
        });
        container.appendChild(card);
    });
}

function setAbsence(btn) {
    const input = btn.parentElement.querySelector('input');
    input.value = -1;
    input.style.backgroundColor = '#fff3cd';
    input.style.borderColor = '#ffeaa7';
    
    setTimeout(() => {
        input.style.backgroundColor = '';
        input.style.borderColor = '';
    }, 1000);
}

function getEmoji(count) {
    if(count === -1) return '🟠';
    if(count < 3) return '🔴';
    return '🟢';
}

function formatDate(dateString) {
    const date = new Date(dateString);
    const day = date.getDate().toString().padStart(2, '0');
    const month = (date.getMonth() + 1).toString().padStart(2, '0');
    const year = date.getFullYear();
    return `${day}/${month}/${year}`;
}

function generateMarkdown() {
    const weekStart = document.getElementById('weekStart').value;
    const weekEnd = document.getElementById('weekEnd').value;
    const t = translations[currentLanguage];
    
    if (!weekStart || !weekEnd) {
        alert(t.selectWeek);
        return;
    }
    
    const startFormatted = formatDate(weekStart);
    const endFormatted = formatDate(weekEnd);
    
    let output = `**${startFormatted} - ${endFormatted}**\n\n`;
    output += `-# 🟢 = ${t.legendTexts.green}\n`;
    output += `-# 🟠 = ${t.legendTexts.orange}\n`;
    output += `-# 🔴 = ${t.legendTexts.red}\n\n`;
    
    names.forEach(name => {
        output += `**${name}:**\n`;
        document.querySelectorAll('.card').forEach(card => {
            if(card.querySelector('h3').innerText === name) {
                card.querySelectorAll('input').forEach(input => {
                    const day = input.dataset.day;
                    const val = parseInt(input.value);
                    const emoji = getEmoji(val);
                    let text = `> **${day}:** ${emoji}`;
                    if(emoji === '🔴') text += ` (${val})`;
                    output += text + '\n';
                });
            }
        });
        output += "\n";
    });
    
    document.getElementById('output').value = output;
}

async function copyToClipboard() {
    const output = document.getElementById('output').value;
    const copyBtn = document.getElementById('copyBtn');
    const t = translations[currentLanguage];
    
    if (!output.trim()) {
        alert(t.generateFirst);
        return;
    }
    
    try {
        await navigator.clipboard.writeText(output);
        copyBtn.textContent = t.copied;
        copyBtn.classList.add('copied');
        
        setTimeout(() => {
            copyBtn.textContent = t.copyBtn;
            copyBtn.classList.remove('copied');
        }, 2000);
    } catch (err) {
        const textarea = document.getElementById('output');
        textarea.select();
        document.execCommand('copy');
        
        copyBtn.textContent = t.copied;
        copyBtn.classList.add('copied');
        
        setTimeout(() => {
            copyBtn.textContent = t.copyBtn;
            copyBtn.classList.remove('copied');
        }, 2000);
    }
}

async function sendToDiscord() {
    const output = document.getElementById('output').value;
    const discordBtn = document.getElementById('discordBtn');
    const t = translations[currentLanguage];
    
    if (!output.trim()) {
        alert(t.generateFirst);
        return;
    }
    
    discordBtn.disabled = true;
    discordBtn.innerHTML = t.discordSending;
    
    const result = await apiCall('sendToDiscord', { message: output });
    
    if (result.success) {
        discordBtn.innerHTML = t.discordSuccess;
        setTimeout(() => {
            discordBtn.innerHTML = t.discordBtn;
            discordBtn.disabled = false;
        }, 3000);
    } else {
        discordBtn.innerHTML = t.discordError;
        setTimeout(() => {
            discordBtn.innerHTML = t.discordBtn;
            discordBtn.disabled = false;
        }, 3000);
    }
}

checkLoginStatus();
createCards();
initializeWeek();
</script>
</body>
</html>