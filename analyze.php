<?php
session_start();
require_once 'services/ApiService.php';

// Restore result after redirect
$result = $_SESSION['result'] ?? null;
$fileType = $_SESSION['fileType'] ?? null;
$content = $_SESSION['content'] ?? null;
$type = $_SESSION['type'] ?? null;
unset($_SESSION['result'], $_SESSION['fileType'], $_SESSION['content'], $_SESSION['type']);

// Initialize API service
$apiService = new ApiService();

// Initialize history if result is set
if ($result && isset($type, $content)) {
    if (!isset($_SESSION['history'])) {
        $_SESSION['history'] = []; // Initialize if not already
    }
    $_SESSION['history'][] = [
        'date' => date('Y-m-d H:i:s'),
        'type' => $type,
        'content' => $content,
        'result' => $result['is_fake'] ? 'Fake' : 'Legit',
        'confidence' => $result['confidence'],
    ];
}

// Handle form submission
$error_message = null;
$uploaded_image_path = null;

if ($_POST) {
    $type = $_POST['type'] ?? '';

    try {
        switch ($type) {
            case 'text':
                $content = $_POST['content'] ?? '';
                if (empty(trim($content))) {
                    throw new Exception('Please enter some text or URL to analyze.');
                }

                // Check if input is a valid URL
                if (filter_var($content, FILTER_VALIDATE_URL)) {
                    $html = @file_get_contents($content);
                    if ($html === false) {
                        throw new Exception('Failed to fetch content from the provided URL.');
                    }

                    libxml_use_internal_errors(true);
                    $doc = new DOMDocument();
                    $doc->loadHTML($html);
                    libxml_clear_errors();

                    $xpath = new DOMXPath($doc);
                    $nodes = $xpath->query("//p");
                    $extractedText = '';
                    foreach ($nodes as $node) {
                        $extractedText .= ' ' . trim($node->textContent);
                    }

                    $content = trim($extractedText);
                    if (empty($content)) {
                        throw new Exception('No article text found at the provided URL.');
                    }
                }

                $result = $apiService->analyzeText($content);
                break;

            case 'image':
                if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
                    throw new Exception('Please upload a valid image file.');
                }

                $upload_dir = 'uploads/';
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }

                $file_extension = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
                $uploaded_image_path = $upload_dir . uniqid('img_') . '.' . $file_extension;

                if (!move_uploaded_file($_FILES['image']['tmp_name'], $uploaded_image_path)) {
                    throw new Exception('Failed to save uploaded image.');
                }

                $tesseractPath = "C:\\Program Files\\Tesseract-OCR\\tesseract.exe";
                $command = "\"$tesseractPath\" " . escapeshellarg($uploaded_image_path) . " stdout 2>&1";
                $ocrText = shell_exec($command);
                $content = trim($ocrText);

                if (empty($content)) {
                    throw new Exception('No text detected in image.');
                }

                $result = $apiService->analyzeText($content);
                break;

            case 'audio':
                if (!isset($_FILES['audio']) || $_FILES['audio']['error'] !== UPLOAD_ERR_OK) {
                    throw new Exception('Please upload a valid audio file.');
                }

                $result = $apiService->analyzeAudio($_FILES['audio']);
                $content = 'Audio: ' . $_FILES['audio']['name'];
                break;

            default:
                throw new Exception('Invalid analysis type.');
        }

        // Check if API returned an error
        if (isset($result['error']) && $result['error']) {
            $error_message = $result['message'];
            $result = null;
        } else {
            // Add to history
            $_SESSION['history'][] = [
                'date' => date('Y-m-d H:i:s'),
                'type' => $type,
                'content' => substr($content, 0, 100) . (strlen($content) > 100 ? '...' : ''),
                'result' => $result['is_fake'] ? 'Fake' : 'Legit',
                'confidence' => $result['confidence'],
                'image_path' => $uploaded_image_path ?? null
            ];

            // ✅ Store result and redirect
            $_SESSION['result'] = $result;
            $_SESSION['fileType'] = $type;

            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        }

    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}

// Check API health status
$healthStatus = $apiService->checkHealth();
?>
<?php
require_once 'db.php'; // Ensure this is the correct path to your db file

$ip = $_SERVER['REMOTE_ADDR'];
$visitedAt = date('Y-m-d H:i:s');
$userAgent = basename($_SERVER['HTTP_USER_AGENT']) ?? 'Unknown';
$pageUrl = basename($_SERVER['REQUEST_URI']) ?? 'Unknown';
$email = $_SESSION['email'] ?? 'Guest'; // Adjust if your session uses a different key

$stmt = $pdo->prepare("INSERT INTO visitor_logs (ip_address, visited_at, user_agent, page_url, email) VALUES (?, ?, ?, ?, ?)");
$stmt->execute([$ip, $visitedAt, $userAgent, $pageUrl, $email]);
?>
<?php
// Handle "Continue without signing in"
if (isset($_GET['guest']) && $_GET['guest'] == 1) {
  $_SESSION['guest'] = true;
}

// Handle "Sign In" clicked while guest
if (isset($_GET['remove_guest']) && $_GET['remove_guest'] == 1) {
  unset($_SESSION['guest']);
  header("Location: index.php"); // Clean URL
  exit;
}

$is_logged_in = isset($_SESSION['user_id']);
$is_guest = isset($_SESSION['guest']) && $_SESSION['guest'] === true;
$show_login = !$is_logged_in && !$is_guest;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analyze Content - VeriFact</title>
    <link rel="stylesheet" href="style.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        .result-actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .action-btn {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 12px 16px;
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius);
            color: var(--text-primary);
            text-decoration: none;
            font-weight: 500;
            transition: var(--transition);
            cursor: pointer;
            font-size: 14px;
        }

        .action-btn:hover {
            background: var(--primary-blue);
            border-color: var(--primary-blue);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }

        .action-btn.speaking {
            background: var(--gradient-primary);
            color: white;
            animation: pulse 1s infinite;
        }

        .action-btn i {
            font-size: 16px;
        }
        .navbar,
.menu-toggle {
  display: none;
}

.nav-menu {
  display: flex;
  gap: 20px;
}

/* Mobile view */
@media (max-width: 768px) {
  .menu-toggle {
    display: block;
    background: none;
    border: none;
    font-size: 28px;
    color: white;
  }

  .navbar {
    display: block;
    position: absolute;
    top: 70px;
    right: 20px;
    background-color: #111;
    padding: 10px;
    border-radius: 8px;
    z-index: 999;
  }

  .nav-links {
    display: none;
    flex-direction: column;
    list-style: none;
    margin: 0;
    padding: 0;
  }

  .nav-links.show {
    display: flex;
  }

  .nav-links li a {
    color: white;
    padding: 10px;
    text-decoration: none;
  }

  .nav-menu {
    display: none; /* hide desktop nav on mobile */
  }
}
</style>
</head>
<body>
    <header class="header">
  <div class="container" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; padding: 16px;">
    
    <!-- Logo -->
    <div class="nav-brand">
      <a href="index.php" class="logo-link" style="display: flex; align-items: center; text-decoration: none; color: inherit;">
        <div class="logo-icon" style="color: white; font-size: 24px; margin-right: 8px;">
          <i class="fas fa-shield-alt"></i>
        </div>
        <h1 class="logo" style="margin: 0; font-size: 24px;">VeriFact</h1>
      </a>
    </div>

    <!-- Mobile Hamburger -->
    <button class="menu-toggle" onclick="toggleMenu()">☰</button>

    <!-- Mobile Nav -->
    <nav class="navbar">
      <ul class="nav-links" id="navMenu">
        <li><a href="index.php">Home</a></li>
        <li><a href="analyze.php">Fact-Checker</a></li>
        <li><a href="cyberSecurity.php">CyberSecurity</a></li>
        <li><a href="#contact">Contact</a></li>
      </ul>
    </nav>

<!-- Desktop Nav -->
<nav class="nav-menu">
  <a href="index.php" class="nav-link ">Home</a>
  <a href="analyze.php" class="nav-link active">Fact-Checker</a>
  <a href="cyberSecurity.php" class="nav-link">CyberSecurity</a>

  <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
    <a href="dashboard.php" class="nav-link">Dashboard</a>
  <?php endif; ?>

  <a href="#contact" class="nav-link">Contact</a>

  <?php if (!$is_logged_in): ?>
    <a href="index.php?remove_guest=1" class="nav-link" style="margin-left:auto; font-weight: bold;">Sign In</a>
  <?php else: ?>
    <!-- Logged-in user -->
    <div class="profile-dropdown" style="position: relative; margin-left: auto;">
      <div class="profile-icon" onclick="toggleDropdown()" style="
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background-color: #6c63ff;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-weight: bold;
        cursor: pointer;
        font-size: 16px;
      ">
        <?php echo strtoupper(substr($_SESSION['username'], 0, 1)); ?>
      </div>
      <div id="dropdownMenu" class="dropdown-menu" style="
        display: none;
        position: absolute;
        right: 0;
        top: 50px;
        background-color: white;
        border: 1px solid #ddd;
        border-radius: 8px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        min-width: 200px;
        z-index: 999;
      ">
        <!-- User email (non-clickable) -->
        <div style="
          padding: 12px 16px;
          font-size: 14px;
          color: #555;
          border-bottom: 1px solid #eee;
        ">
          <?php echo isset($_SESSION['email']) ? htmlspecialchars($_SESSION['email']) : 'No email available'; ?>
        </div>
        <a href="settings.php" class="dropdown-item" style="
          display: block;
          padding: 12px 16px;
          color: #333;
          text-decoration: none;
          border-bottom: 1px solid #eee;
        ">Settings</a>
        <a href="logout.php" class="dropdown-item" style="
          display: block;
          padding: 12px 16px;
          color: #e74c3c;
          text-decoration: none;
        ">Logout</a>
      </div>
    </div>
  <?php endif; ?>
</nav>
  </header>

    <main class="analyze-page">
        <div class="container">
            <div class="page-header">
                <h1>Verify Content</h1>
                <p>Upload text, images, or audio to check for misinformation using our AI-powered analysis</p>
            </div>

            <?php if ($error_message): ?>
                <div class="error-message">
                    <i class="fas fa-exclamation-triangle"></i>
                    <span><?php echo htmlspecialchars($error_message); ?></span>
                </div>
            <?php endif; ?>

            <div class="analyze-container">
                <div class="analyzer-tabs">
                    <button class="tab-btn active" onclick="switchTab('text')">
                        <i class="fas fa-file-text"></i>
                        Text Checker
                    </button>
                    <button class="tab-btn" onclick="switchTab('image')">
                        <i class="fas fa-image"></i>
                        Image Checker
                    </button>
                    <button class="tab-btn" onclick="switchTab('audio')">
                        <i class="fas fa-microphone"></i>
                        Audio Checker
                    </button>
                    <button class="tab-btn" onclick="switchTab('history')">
                        <i class="fas fa-history"></i>
                        History
                    </button>
                </div>

                <?php $isLoggedIn = isset($_SESSION['user_id']); ?>

<!-- Text Checker Tab -->
<div id="text-tab" class="tab-content active">
    <form method="POST" class="analyzer-form">
        <input type="hidden" name="type" value="text">
        <div class="input-group">
            <label for="text-content">
                <i class="fas fa-edit"></i> 
                Paste news article, headline, or URL
            </label>
            <div class="textarea-container">
                <textarea 
                    id="text-content" 
                    name="content" 
                    placeholder="Paste the news article, headline, URL, or social media post you want to verify..."
                    rows="8"
                    required
                ><?php echo htmlspecialchars($_POST['content'] ?? ''); ?></textarea>
                <button type="button" class="voice-btn" onclick="startVoiceInput()" title="Voice Input">
                    <i class="fas fa-microphone"></i>
                </button>
            </div>
        </div>
        <button type="submit" class="btn btn-primary btn-lg">
            <i class="fas fa-search"></i>
            Analyze Text
        </button>
    </form>
</div>

<!-- Image Checker Tab -->
<div id="image-tab" class="tab-content">
    <form method="POST" enctype="multipart/form-data" class="analyzer-form">
        <input type="hidden" name="type" value="image">
        <div class="input-group">
            <label>
                <i class="fas fa-images"></i> 
                Upload image-based news content
            </label>
            <div class="file-upload-area" onclick="document.getElementById('image-upload').click()">
                <div class="upload-content">
                    <i class="fas fa-cloud-upload-alt"></i>
                    <h3>Drag & drop or click to upload</h3>
                    <p>Support for JPG, PNG, WebP files up to 10MB</p>
                </div>
                <input type="file" id="image-upload" name="image" accept="image/*" style="display: none;">
            </div>
        </div>
        <button type="submit" class="btn btn-primary btn-lg">
            <i class="fas fa-image"></i>
            Analyze Image
        </button>
    </form>
</div>

<!-- Audio Checker Tab -->
<div id="audio-tab" class="tab-content">
    <form method="POST" enctype="multipart/form-data" class="analyzer-form">
        <input type="hidden" name="type" value="audio">
        <div class="input-group">
            <label>
                <i class="fas fa-volume-up"></i> 
                Upload audio content
            </label>
            <div class="file-upload-area" onclick="document.getElementById('audio-upload').click()">
                <div class="upload-content">
                    <i class="fas fa-cloud-upload-alt"></i>
                    <h3>Drag & drop or click to upload</h3>
                    <p>Support for MP3, WAV, M4A files up to 25MB</p>
                </div>
                <input type="file" id="audio-upload" name="audio" accept="audio/*" style="display: none;">
            </div>
        </div>
        <button type="submit" class="btn btn-primary btn-lg">
            <i class="fas fa-microphone"></i>
            Analyze Audio
        </button>
    </form>
</div>

<!-- History Tab -->
<?php if ($isLoggedIn): ?>
    <div id="history-tab" class="tab-content">
        <div class="history-container">
            <div class="history-header">
                <h3><i class="fas fa-history"></i> Analysis History</h3>
                <button class="btn btn-outline" onclick="clearHistory()">
                    <i class="fas fa-trash"></i>
                    Clear History
                </button>
            </div>
            <?php if (empty($_SESSION['history'])): ?>
                <div class="empty-state">
                    <i class="fas fa-history"></i>
                    <p>No analysis history yet. Start by checking some content!</p>
                </div>
            <?php else: ?>
                <div class="history-table">
                    <table>
                        <thead>
                            <tr>
                                <th><i class="fas fa-calendar"></i> Date</th>
                                <th><i class="fas fa-tag"></i> Type</th>
                                <th><i class="fas fa-file-alt"></i> Content</th>
                                <th><i class="fas fa-check-circle"></i> Result</th>
                                <th><i class="fas fa-percentage"></i> Confidence</th>
                                <th><i class="fas fa-eye"></i> View</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (array_reverse($_SESSION['history']) as $index => $item): ?>
                                <tr>
                                    <td><?php echo date('M j, Y H:i', strtotime($item['date'])); ?></td>
                                    <td><span class="type-badge <?php echo $item['type'] ?? 'unknown'; ?>"><?php echo ucfirst($item['type'] ?? 'unknown'); ?></span></td>
                                    <td class="content-preview"><?php echo htmlspecialchars($item['content'] ?? ''); ?></td>
                                    <td><span class="result-badge <?php echo strtolower($item['result']); ?>"><?php echo $item['result']; ?></span></td>
                                    <td><?php echo $item['confidence']; ?>%</td>
                                    <td>
                                        <button class="btn btn-sm btn-secondary" onclick="showSummaryModal(<?php echo htmlspecialchars(json_encode($item)); ?>)">
                                            <i class="fas fa-eye"></i> View
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php else: ?>
    <div id="history-tab" class="tab-content disabled">
        <div class="empty-state">
            <i class="fas fa-user-lock" style="font-size: 3rem; color: #999;"></i>
            <h3>You're not signed in</h3>
            <p>Please sign in to view your analysis history.</p>
            <a href="index.php?remove_guest=1" class="btn btn-primary mt-2">
                <i class="fas fa-sign-in-alt"></i> Sign In
            </a>
        </div>
    </div>
<?php endif; ?>

<!-- Summary Modal -->
<div id="summaryModal" class="modal" style="display:none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.6); align-items: center; justify-content: center; z-index: 9999;">
    <div class="modal-content" style="background: #fff; color: #333; padding: 24px; border-radius: 12px; width: 100%; max-width: 500px; position: relative;">
        <button onclick="closeSummaryModal()" style="position: absolute; top: 12px; right: 12px; background: none; border: none; font-size: 1.2rem; cursor: pointer;">
            <i class="fas fa-times"></i>
        </button>
        <h3 style="margin-top: 0;"><i class="fas fa-info-circle"></i> Analysis Summary</h3>
        <div id="summaryContent" style="margin-top: 16px; white-space: pre-line;"></div>
    </div>
</div>

<script>
function escapeHtml(unsafe) {
    return unsafe
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

function showSummaryModal(data) {
    const summary = Date: ${new Date(data.date).toLocaleString()}\n\nType: ${data.type}\n\nResult: ${data.result}\n\nConfidence: ${data.confidence}%\n\nSummary:\n${data.explanation ? escapeHtml(data.explanation).substring(0, 300) + '...' : 'No summary available.'};
    document.getElementById('summaryContent').innerText = summary;
    document.getElementById('summaryModal').style.display = 'flex';
}

function closeSummaryModal() {
    document.getElementById('summaryModal').style.display = 'none';
}
</script>
    
            <!-- Results Section -->
                            <?php if ($result && !isset($result['error'])): ?>
                    <div class="results-container">
                        <div class="result-card">
                            <div class="result-header">
                                <h2><i class="fas fa-brain"></i> Analysis Complete</h2>
                                <div class="result-actions">
                                    <button class="action-btn speak-btn" onclick="speakResult()" title="Listen to AI Analysis">
                                        <i class="fas fa-volume-up"></i>
                                        <span></span>
                                    </button>
                                    
                                    <!-- <button class="action-btn share-btn" onclick="shareResult()" title="Share Result">
                                        <i class="fas fa-share-alt"></i>
                                        <span>Share</span>
                                    </button> -->
                                    
                                </div>
                            </div>

                            <!-- IMAGE PREVIEW START -->
                            <?php if (!empty($uploaded_image_path)): ?>
                                <div class="image-preview-section" style="margin: 24px 0;">
                                    <h3><i class="fas fa-image"></i> Uploaded Image</h3>
                                    <img src="<?php echo htmlspecialchars($uploaded_image_path); ?>" alt="Uploaded Image" class="preview-thumbnail" onclick="openImageModal(this)" style="max-width: 200px; border-radius: 8px; cursor: zoom-in; transition: transform 0.2s;">
                                </div>
                            <?php endif; ?>
                            <!-- IMAGE PREVIEW END -->

                            <div class="confidence-display">
                                <div class="confidence-circle <?php echo $result['is_fake'] ? 'fake' : 'legit'; ?>">
                                    <svg class="progress-ring" width="120" height="120">
                                        <circle class="progress-ring-circle" cx="60" cy="60" r="54"></circle>
                                    </svg>
                                    <div class="confidence-text">
                                        <span class="percentage"><?php echo $result['confidence']; ?>%</span>
                                        <span class="label"><?php echo $result['is_fake'] ? 'Likely Fake' : 'Likely Legit'; ?></span>
                                    </div>
                                </div>
                            </div>

                            <div class="result-explanation">
                                <h3><i class="fas fa-brain"></i> AI Analysis</h3>
                                <div class="explanation-content">
                                    <p><?php echo nl2br(htmlspecialchars($result['explanation'])); ?></p>
                                </div>
                            </div>

                            <?php if (!empty($result['cybersecurity_tips'])): ?>
                                        <div class="cybersecurity-tips" style="margin-top: 32px;">
                                            <button onclick="toggleTips()" class="dropdown-toggle" style="display: flex; align-items: center; gap: 10px; background: none; border: none; font-size: 1.2rem; color: var(--text-primary); cursor: pointer;">
                                                <i class="fas fa-shield-alt"></i> <span>Additional Cybersecurity Tips</span>
                                                <i id="arrowIcon" class="fas fa-chevron-down" style="transition: transform 0.3s;"></i>
                                            </button>

                                            <div id="tipsContent" style="margin-top: 16px; max-height: 0; overflow: hidden; transition: max-height 0.5s ease, opacity 0.3s ease; opacity: 0;">
                                                <div class="tips-grid" style="display: grid; gap: 16px; background: rgba(245, 158, 11, 0.1); border: 1px solid #f59e0b; border-radius: var(--border-radius-lg); padding: 24px;">
                                                    <?php foreach ($result['cybersecurity_tips'] as $index => $tip): ?>
                                                        <div class="tip-card" style="display: flex; gap: 12px; padding: 16px; background: rgba(255, 255, 255, 0.05); border-radius: 8px;">
                                                            <div class="tip-number" style="width: 24px; height: 24px; background: var(--gradient-primary); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 600; flex-shrink: 0;">
                                                                <?php echo $index + 1; ?>
                                                            </div>
                                                            <div class="tip-content">
                                                                <p style="margin: 0; color: var(--text-secondary);"><?php echo htmlspecialchars($tip); ?></p>
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        </div>

                                        <script>
                                            function toggleTips() {
                                                const content = document.getElementById("tipsContent");
                                                const arrow = document.getElementById("arrowIcon");
                                                if (content.style.maxHeight && content.style.maxHeight !== "0px") {
                                                    content.style.maxHeight = "0px";
                                                    content.style.opacity = "0";
                                                    arrow.style.transform = "rotate(0deg)";
                                                } else {
                                                    content.style.maxHeight = content.scrollHeight + "px";
                                                    content.style.opacity = "1";
                                                    arrow.style.transform = "rotate(180deg)";
                                                }
                                            }
                                        </script>
                                    <?php endif; ?>
                            <div class="trusted-sources" style="margin-top: 32px;">
                                <h3><i class="fas fa-check-circle"></i> Trusted Sources</h3>
                                <div class="sources-list" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; margin-top: 16px;">
                                    <?php foreach ($result['sources'] as $source): ?>
                                        <a href="<?php echo htmlspecialchars($source['url']); ?>" target="_blank" class="source-link" style="display: flex; align-items: center; gap: 8px; padding: 12px; background: rgba(59, 130, 246, 0.1); border: 1px solid var(--primary-blue); border-radius: 8px; color: var(--primary-blue); text-decoration: none; transition: var(--transition);">
                                            <i class="fas fa-external-link-alt"></i>
                                            <span><?php echo htmlspecialchars($source['name']); ?></span>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- IMAGE ZOOM MODAL -->
                    <div id="imageModal" class="image-modal" style="display:none; position:fixed; z-index:9999; top:0; left:0; width:100%; height:100%; background-color: rgba(0,0,0,0.8); justify-content:center; align-items:center;">
                        <span onclick="closeImageModal()" style="position:absolute; top:20px; right:40px; font-size:32px; color:#fff; cursor:pointer;">&times;</span>
                        <img id="modalImage" style="max-width:90%; max-height:90%; border-radius: 12px;" />
                    </div>

                    <script>
                        function openImageModal(img) {
                            document.getElementById("modalImage").src = img.src;
                            document.getElementById("imageModal").style.display = "flex";
                        }

                        function closeImageModal() {
                            document.getElementById("imageModal").style.display = "none";
                        }

                        // Optional: close modal on background click
                        document.getElementById("imageModal").addEventListener("click", function(e) {
                            if (e.target.id === "imageModal") {
                                closeImageModal();
                            }
                        });
                    </script>
                <?php endif; ?>

                        </div>
                    </main>

    <footer id="contact" class="footer">
        <div class="container">
            <div class="footer-content">
                <div class="footer-section">
                    <h3>VeriFact</h3>
                    <p>Empowering truth through AI-powered verification. Join the fight against misinformation with cutting-edge technology.</p>
                    <div class="social-links">
                        <a href="#"><i class="fab fa-twitter"></i></a>
                        <a href="#"><i class="fab fa-facebook"></i></a>
                        <a href="#"><i class="fab fa-linkedin"></i></a>
                        <a href="#"><i class="fab fa-instagram"></i></a>
                    </div>
                </div>
                <div class="footer-section">
                    <h4>Product</h4>
                    <ul>
                        <li><a href="analyze.php">Text Checker</a></li>
                        <li><a href="analyze.php">Image Verification</a></li>
                        <li><a href="analyze.php">Audio Analysis</a></li>
                        <li><a href="#">API Access</a></li>
                    </ul>
                </div>
                <div class="footer-section">
                    <h4>Company</h4>
                    <ul>
                        <li><a href="#">About Us</a></li>
                        <li><a href="#">Careers</a></li>
                        <li><a href="#">Press</a></li>
                        <li><a href="#">Contact</a></li>
                    </ul>
                </div>
                <div class="footer-section">
                    <h4>Legal</h4>
                    <ul>
                        <li><a href="#">Privacy Policy</a></li>
                        <li><a href="#">Terms of Service</a></li>
                        <li><a href="#">Cookie Policy</a></li>
                        <li><a href="#">GDPR</a></li>
                    </ul>
                </div>
            </div>
            <div class="footer-bottom">
                <p>&copy; 2025 VeriFact. All rights reserved. Fighting misinformation with AI.</p>
            </div>
        </div>
    </footer>


    <script src="script.js"></script>
    <script>
        // Initialize confidence circle animation
        <?php if ($result && !isset($result['error'])): ?>
            document.addEventListener('DOMContentLoaded', function() {
                animateConfidenceCircle(<?php echo $result['confidence']; ?>);
            });
        <?php endif; ?>

        // Copy result function
        function copyResult() {
            const explanation = document.querySelector('.explanation-content').innerText;
            const confidence = document.querySelector('.percentage').innerText;
            const label = document.querySelector('.label').innerText;
            
            const textToCopy = `VeriFact Analysis Result\n\nConfidence: ${confidence} ${label}\n\nAnalysis:\n${explanation}`;
            
            navigator.clipboard.writeText(textToCopy).then(() => {
                const copyBtn = document.querySelector('.copy-btn');
                const originalIcon = copyBtn.innerHTML;
                copyBtn.innerHTML = '<i class="fas fa-check"></i>';
                setTimeout(() => {
                    copyBtn.innerHTML = originalIcon;
                }, 2000);
            });
        }
        
    </script>

    <script>
  function toggleMenu() {
    document.getElementById("navMenu").classList.toggle("show");
  }
</script>
<script>
function clearHistory() {
    fetch('clear_history.php', {
        method: 'POST'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload(); // Reload the page to reflect the cleared history
        }
    });
}
</script>

<script>
function toggleDropdown() {
  const menu = document.getElementById('dropdownMenu');
  menu.style.display = (menu.style.display === 'block') ? 'none' : 'block';
}

// Optional: close dropdown if user clicks outside
window.addEventListener('click', function(e) {
  const menu = document.getElementById('dropdownMenu');
  const icon = document.querySelector('.profile-icon');
  if (!icon.contains(e.target) && !menu.contains(e.target)) {
    menu.style.display = 'none';
  }
});
</script>

</body>
</html>
