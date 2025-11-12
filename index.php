<?php
// index.php - Single-file chat v3
date_default_timezone_set('UTC');

// --- CONFIG ---
// 1. Get a GIPHY API Key: https://developers.giphy.com/dashboard/
// 2. Paste your (Production) Key here.
// 3. If you leave this empty, GIF search will be disabled.
$GIPHY_API_KEY = ''; // <-- PASTE GIPHY API KEY HERE
// --- END CONFIG ---


// Paths
$chatFile = __DIR__ . '/chat.json';
$uploadDir = __DIR__ . '/uploads';

// ensure files/dirs
if(!file_exists($chatFile)) file_put_contents($chatFile, json_encode(['messages'=>[],'meta'=>[]]));
if(!file_exists($uploadDir)) @mkdir($uploadDir, 0777, true);

// helper: load chat (with lock)
function load_store(){
    global $chatFile;
    $raw = @file_get_contents($chatFile);
    if($raw === false) return ['messages'=>[],'meta'=>[]];
    $data = json_decode($raw, true);
    if(!is_array($data)) $data = ['messages'=>[],'meta'=>[]];
    if(!isset($data['messages'])) $data['messages'] = [];
    if(!isset($data['meta'])) $data['meta'] = [];
    return $data;
}
function save_store($data){
    global $chatFile;
    file_put_contents($chatFile, json_encode($data), LOCK_EX);
}

// helper delete all uploaded files
function delete_uploads(){
    global $uploadDir;
    $files = glob($uploadDir . '/*');
    foreach($files as $f) if(is_file($f)) @unlink($f);
}

// ---------- handle message POST ----------
if($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['deleteCode']) && !isset($_POST['action'])){
    $name = isset($_POST['name']) ? trim($_POST['name']) : '';
    $name = htmlspecialchars($name ?: 'Gast');
    $pfp = isset($_POST['pfp']) ? trim($_POST['pfp']) : null;
    if ($pfp !== null && !preg_match('/^https?:.*/', $pfp) && !preg_match('/^data:image.*/', $pfp)) {
        $pfp = null;
    }

    $msg = isset($_POST['msg']) ? trim($_POST['msg']) : '';
    $whisper_to = null;

    if (preg_match('/^\/w\s+([^\s]+)\s+(.+)/s', $msg, $matches)) {
        $whisper_to = htmlspecialchars($matches[1]);
        $msg = htmlspecialchars($matches[2]); // The message text
    } else {
        $msg = htmlspecialchars($msg); // The original message text, escaped
    }

    $sticker = isset($_POST['sticker']) ? trim($_POST['sticker']) : null;
    if ($sticker !== null && !preg_match('/^https?:\/\/.*/', $sticker)) {
        $sticker = null;
    }

    $fileInfo = null;
    if(isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK){
        $tmp = $_FILES['file']['tmp_name'];
        $orig = basename($_FILES['file']['name']);
        $safe = preg_replace("/[^a-zA-Z0-9\-\._]/", "_", $orig);
        $fname = time() . "_" . rand(1000,9999) . "_" . $safe;
        $dest = $uploadDir . '/' . $fname;
        if(move_uploaded_file($tmp, $dest)){
            $fileInfo = [
                'name' => htmlspecialchars($orig),
                'path' => 'uploads/' . $fname,
                'type' => $_FILES['file']['type'] ?? mime_content_type($dest)
            ];
        }
    }

    if($msg !== '' || $fileInfo !== null || $sticker !== null){
        $store = load_store();
        $store['messages'][] = [
            'id'    => time() . '_' . rand(1000,9999),
            'type'  => 'user',
            'name'  => $name,
            'pfp'   => $pfp,
            'msg'   => $msg,
            'whisper_to' => $whisper_to,
            'file'  => $fileInfo,
            'sticker' => $sticker,
            'reactions' => new stdClass(),
            'time'  => time()
        ];

        // check for image count
        $imageCount = 0;
        $imgs = glob($uploadDir . '/*');
        foreach($imgs as $p){
            $ext = strtolower(pathinfo($p, PATHINFO_EXTENSION));
            if(in_array($ext, ['jpg','jpeg','png','gif','webp'])) $imageCount++;
        }

        // schedule deletion if too many images
        if($imageCount > 5){
            if(empty($store['meta']['delete_at']) || $store['meta']['delete_at'] <= time()){
                $deleteAt = time() + 5;
                $store['meta']['delete_at'] = $deleteAt;
                $store['messages'][] = [
                    'id'   => time() . '_' . rand(1000,9999),
                    'type' => 'bot',
                    'name' => 'ServerBot',
                    'msg'  => 'Chat wird gelöscht in 5 Sekunden...',
                    'delete_at' => $deleteAt,
                    'time' => time()
                ];
            }
        }

        save_store($store);
    }

    header('Content-Type: application/json');
    echo json_encode(['status'=>'ok']);
    exit;
}

// ---------- handle reaction POST ----------
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'react'){
    $msgId = $_POST['id'] ?? null;
    $emoji = $_POST['emoji'] ?? null;
    $name = $_POST['name'] ?? 'Gast';

    if($msgId && $emoji){
        $store = load_store();
        $found = false;
        foreach($store['messages'] as $i => $msg){
            if($msg['id'] === $msgId){
                if(!isset($store['messages'][$i]['reactions'])) {
                    $store['messages'][$i]['reactions'] = new stdClass();
                }
                
                // PHP stdClass/empty object behavior fix
                $reactions = (array)$store['messages'][$i]['reactions'];

                if(!isset($reactions[$emoji])){
                    $reactions[$emoji] = [];
                }
                
                if(in_array($name, $reactions[$emoji])){
                    // remove reaction
                    $reactions[$emoji] = array_values(array_filter($reactions[$emoji], function($n) use ($name){
                        return $n !== $name;
                    }));
                    if(empty($reactions[$emoji])){
                        unset($reactions[$emoji]);
                    }
                } else {
                    // add reaction
                    $reactions[$emoji][] = $name;
                }
                $store['messages'][$i]['reactions'] = (object)$reactions;
                $found = true;
                break;
            }
        }
        if($found) save_store($store);
    }
    echo json_encode(['status'=>'ok']);
    exit;
}


// ---------- handle delete code POST ----------
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['deleteCode'])){
    $code = $_POST['deleteCode'];
    if($code === '1204'){
        $store = load_store();
        delete_uploads();
        $store['messages'] = [];
        $store['meta'] = [];
        save_store($store);
        echo 'deleted';
    } else {
        echo 'wrong';
    }
    exit;
}

// ---------- handle GIPHY search GET ----------
if(isset($_GET['action']) && $_GET['action'] === 'search_gif'){
    header('Content-Type: application/json');
    if(empty($GIPHY_API_KEY)){
        echo json_encode(['error' => 'GIPHY API Key not configured on server.']);
        exit;
    }
    $q = urlencode($_GET['q'] ?? 'hello');
    $url = "https://api.giphy.com/v1/gifs/search?api_key={$GIPHY_API_KEY}&q={$q}&limit=20&offset=0&rating=g&lang=en";
    
    $result = @file_get_contents($url);
    if($result === false){
        echo json_encode(['error' => 'Failed to contact GIPHY API.']);
        exit;
    }
    
    $data = json_decode($result, true);
    $output = [];
    if($data && isset($data['data'])){
        foreach($data['data'] as $gif){
            $output[] = [
                'id' => $gif['id'],
                'url' => $gif['images']['fixed_width_downsampled']['url'], // The one to send
                'preview' => $gif['images']['fixed_width_still']['url'] // The one to show
            ];
        }
    }
    echo json_encode($output);
    exit;
}

// ---------- handle load GET ----------
if(isset($_GET['action']) && $_GET['action'] === 'load'){
    $store = load_store();
    if(!empty($store['meta']['delete_at']) && time() >= intval($store['meta']['delete_at'])){
        delete_uploads();
        $store['messages'] = [];
        $store['meta'] = [];
        save_store($store);
        $store = load_store();
    }

    header('Content-Type: application/json');
    echo json_encode(['server_time' => time(), 'messages' => $store['messages'], 'meta' => $store['meta']]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>rlgchat (by pd)</title>
<style>
:root{--bg:#0b0b0b;--panel:#151515;--accent:#128C7E;--muted:#999;}
html,body{height:100%}
body{
  background:var(--bg);
  color:#eee;
  font-family:Arial, Helvetica, sans-serif;
  margin:0;
  padding:18px;
  display:flex;
  flex-direction:column;
  align-items:center;
}
.chat-wrapper{
    width:100%;
    max-width:100%;
    display:flex;
    flex-direction:column;
    height:100%;
    position: relative; /* For scroll button positioning */
}
.top{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;}
#usernameDisplay{font-size:14px;color:var(--muted);display:flex;align-items:center;gap:8px;}
#chatbox{
  background:var(--panel);
  border-radius:12px;
  padding:12px;
  flex-grow:1;
  overflow:auto;
  box-shadow:0 6px 18px rgba(0,0,0,0.6);
  display: flex;
  flex-direction: column;
}

/* --- Animation --- */
@keyframes fadeInMsg {
  from { opacity: 0; }
  to { opacity: 1; }
}
.msg:last-child {
    animation: fadeInMsg 0.3s ease-out;
}
button, .fileLabel, .stickerTab, .reaction, .sticker-wrapper img, .react-btn {
    transition: all 0.2s ease-out;
}

.msg{
  margin:2px 0;
  max-width:80%;
  width:fit-content;
  display: flex;
  flex-direction: column;
}
.msg-inner{
  padding:6px 10px;
  border-radius:12px;
  position: relative;
  overflow-wrap: break-word; /* FIX: wrap long text */
}
.msg-inner a {
    color: #8af; /* Make links stand out */
    word-break: break-all; /* FIX: Break long links */
}
.msg .meta{font-size:12px;color:var(--muted);margin-bottom:4px;display:flex;align-items:center;gap:6px;}
.msg .meta .pfp{width:20px;height:20px;border-radius:50%;background:#333;object-fit:cover;}
.msg.user{margin-right:auto;align-items:flex-start;}
.msg.user .msg-inner{background:#1b1b1b;}
.msg.me{margin-left:auto;align-items:flex-end;}
.msg.me .msg-inner{background:#063;color:#fff;text-align:right;}
.msg.me .meta{flex-direction: row-reverse;}
.msg.bot{
  background:#222;
  color:#ffd;
  font-weight:600;
  text-align:center;
  border:1px solid rgba(255,255,255,0.03);
  margin-left:auto;
  margin-right:auto;
  padding: 6px 10px;
  border-radius: 12px;
}
.mention {
    background: #ffc;
    color: #000;
    padding: 1px 3px;
    border-radius: 3px;
    font-weight: 600;
}
.msg.whisper .msg-inner {
    background: #3a3a3a;
    border: 1px dashed var(--muted);
}
.msg.whisper .meta::before {
    content: 'WHISPER ';
    font-weight: bold;
    color: #f9a;
    font-size: 10px;
}
.msg.me.whisper .meta::after {
    content: 'WHISPER ';
    font-weight: bold;
    color: #f9a;
    font-size: 10px;
}
.dm-btn {
    display: none;
    font-size: 10px;
    color: #aaa;
    cursor: pointer;
    margin: 0 5px;
}
.msg.user:not(.whisper):hover .dm-btn { display: inline-block; }
.dm-btn:hover { color: #fff; }
.inputBar{display:flex;gap:8px;align-items:center;margin-top:12px;}
.fileLabel{width:40px;height:40px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:transparent;border:1px solid rgba(255,255,255,0.03);cursor:pointer;}
.fileLabel:hover{background:rgba(255,255,255,0.02);}
.fileLabel svg{width:20px;height:20px;opacity:0.9;}
#file{display:none;}
.inputWrap{flex:1;display:flex;align-items:center;background:#121212;padding:8px 10px;border-radius:999px;gap:8px;}
#msg{flex:1;background:transparent;border:none;color:#fff;font-size:15px;outline:none;}
#sendBtn{background:var(--accent);color:#fff;border:none;padding:10px 14px;border-radius:10px;cursor:pointer;}
#sendBtn:hover { background: #15a392; }
.thumb{max-width:160px;max-height:120px;display:block;margin-top:6px;border-radius:6px;}
.note{color:var(--muted);font-size:13px;margin-top:8px;}

.msg .sticker {
    max-width: 128px;
    max-height: 128px;
    display: block;
    margin: 5px 0;
    background: rgba(255,255,255,0.05);
    border-radius: 6px;
    cursor: pointer;
}
#stickerBtn {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    background: transparent;
    border: 1px solid rgba(255, 255, 255, 0.03);
    cursor: pointer;
    color: #eee;
    padding: 0;
}
#stickerBtn:hover { background: rgba(255, 255, 255, 0.02); }
#stickerBtn svg { width: 20px; height: 20px; opacity: 0.9; }

#stickerPanel {
    display: none; /* <-- FIX: War 'visibility: hidden' */
    background: var(--panel);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 12px;
    padding: 10px;
    margin-top: 10px;
    box-shadow: 0 6px 18px rgba(0, 0, 0, 0.6);
    position: relative;
}
#stickerPanel.visible {
    display: block; /* <-- FIX: War 'visibility: visible' */
}
.close-panel-btn {
    position: absolute;
    top: 10px;
    right: 12px;
    background: #333;
    border: none;
    color: #aaa;
    font-size: 16px;
    cursor: pointer;
    padding: 0;
    line-height: 1;
    z-index: 12;
    width: 24px;
    height: 24px;
    border-radius: 50%;
    font-weight: bold;
}
.close-panel-btn:hover { color: #fff; background: #444; }
#stickerTabs { display: flex; border-bottom: 1px solid #333; margin-bottom: 10px; }
.stickerTab { padding: 8px 12px; cursor: pointer; color: var(--muted); }
.stickerTab.active { color: #fff; border-bottom: 2px solid var(--accent); }
.stickerTabContent { display: none; }
.stickerTabContent.active { display: block; }
#stickerSearchWrap { display: flex; gap: 8px; }
#stickerSearchWrap input { flex: 1; background: #333; border: 1px solid #444; color: #fff; padding: 8px; border-radius: 6px; }
#stickerSearchWrap button { background: var(--accent); color: #fff; border: none; padding: 8px 10px; border-radius: 6px; cursor: pointer; }
#stickerSearchWrap button:hover { background: #15a392; }
#stickerGrid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(60px, 1fr));
    gap: 8px;
    max-height: 200px;
    overflow-y: auto;
}
.sticker-wrapper {
    position: relative;
    background: #333;
    border-radius: 4px;
}
#stickerGrid img, #stickerGrid-search img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    cursor: pointer;
    border-radius: 4px;
    display: block;
}
#stickerGrid img:hover, #stickerGrid-search img:hover {
    transform: scale(1.1);
}
.remove-sticker-btn {
    position: absolute;
    top: -5px;
    right: -5px;
    background: #e53935;
    color: white;
    border: none;
    border-radius: 50%;
    width: 18px;
    height: 18px;
    font-weight: bold;
    cursor: pointer;
    line-height: 18px;
    text-align: center;
    font-size: 14px;
    padding: 0;
}

#uploadPreview {
    display: none; /* <-- FIX: War 'visibility: hidden' */
    position: relative;
    background: #222;
    padding: 8px;
    border-radius: 8px;
    margin-top: 8px;
    width: fit-content;
    border: 1px solid rgba(255,255,255,0.1);
}
#uploadPreview.visible {
    display: block; /* <-- FIX: War 'visibility: visible' */
}
#uploadPreview img {
    max-width: 120px;
    max-height: 120px;
    border-radius: 4px;
    display: block;
}
#removeUpload {
    position: absolute;
    top: -8px;
    right: -8px;
    background: #e53935;
    color: white;
    border: none;
    border-radius: 50%;
    width: 22px;
    height: 22px;
    font-weight: bold;
    cursor: pointer;
    line-height: 22px;
    text-align: center;
    font-size: 16px;
}

.react-btn {
    display: none;
    position: absolute;
    top: -10px;
    right: 5px;
    background: #333;
    border: 1px solid #555;
    border-radius: 10px;
    padding: 4px;
    cursor: pointer;
    z-index: 10;
}
.react-btn:hover { background: #444; }
.msg:hover .react-btn { display: block; }
.msg.me:hover .react-btn { left: 5px; right: auto; }
.react-btn-inner { font-size: 12px; }

.emoji-picker {
    display: none;
    position: absolute;
    bottom: 100%; /* Default to above */
    right: 0;
    background: var(--panel);
    border: 1px solid #444;
    border-radius: 8px;
    padding: 4px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.5);
    z-index: 11;
    display: grid;
    grid-template-columns: repeat(6, 1fr);
    gap: 2px;
    margin-bottom: 3px;
    margin-top: 3px;
}
.msg.me .emoji-picker { left: 0; right: auto; }
.emoji-picker span {
    font-size: 18px;
    cursor: pointer;
    padding: 2px;
    border-radius: 4px;
    text-align: center;
}
.emoji-picker span:hover { background: #444; }

.reactions-wrap {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
    margin-top: 4px;
    margin-left: 10px;
}
.msg.me .reactions-wrap {
    margin-left: 0;
    margin-right: 10px;
    justify-content: flex-end;
}
.reaction {
    background: #2a2a2a;
    border: 1px solid #444;
    border-radius: 10px;
    padding: 2px 6px;
    font-size: 12px;
    cursor: pointer;
}
.reaction.me {
    background: var(--accent);
    border-color: var(--accent);
    color: #fff;
}
.reaction:hover {
    transform: scale(1.05);
}

/* Scroll to Bottom Button */
#scrollToBottom {
    display: none; /* Hide by default */
    position: absolute;
    bottom: 125px; /* Above all input bars (was 110px) */
    right: 20px; /* Inside the chatbox padding (was 30px) */
    z-index: 100;
    background: var(--accent);
    color: #fff;
    border: none;
    width: 40px;
    height: 40px;
    border-radius: 50%;
    font-size: 24px;
    cursor: pointer;
    opacity: 0.8;
    transition: opacity 0.2s ease, transform 0.2s ease;
}
#scrollToBottom:hover {
    opacity: 1;
    transform: scale(1.05);
}
</style>
</head>
<body>
<div class="chat-wrapper">
  <div class="top">
    <div><h3 style="margin:0">rlgchat</h3><div id="usernameDisplay"></div></div>
    <div style="display:flex; gap: 6px;">
        <button id="editPfpBtn" style="background:transparent;border:1px solid rgba(255,255,255,0.05);padding:6px 8px;border-radius:6px;cursor:pointer;color:#ddd;">profilbild ändern</button>
        <button id="editNameBtn" style="background:transparent;border:1px solid rgba(255,255,255,0.05);padding:6px 8px;border-radius:6px;cursor:pointer;color:#ddd;">name ändern</button>
        <button id="deleteBtn" style="background:transparent;border:1px solid rgba(255,100,100,0.1);padding:6px 8px;border-radius:6px;cursor:pointer;color:#f99;">chat löschen</button>
    </div>
  </div>

  <div id="chatbox" aria-live="polite"><i style="color:#777">noch keine nachrichten...</i></div>
  
  <button id="scrollToBottom" title="Nach unten scrollen">&darr;</button>

  <div id="stickerPanel">
    <button id="closeStickerPanel" class="close-panel-btn" title="Schließen">&times;</button>
    <div id="stickerTabs">
        <div class="stickerTab active" data-tab="myStickers">favoriten</div>
        <div class="stickerTab" data-tab="gifSearch">neue sticker suchen</div>
    </div>
    <div id="myStickers" class="stickerTabContent active">
        <div id="stickerGrid"></div>
    </div>
    <div id="gifSearch" class="stickerTabContent">
        <div id="stickerSearchWrap">
            <input type="text" id="stickerSearchInput" placeholder="suchen...">
            <button id="stickerSearchBtn">Suchen</button>
        </div>
        <div id="stickerGrid-search" style="margin-top:10px; display:grid; grid-template-columns: repeat(auto-fill, minmax(60px, 1fr)); gap: 8px; max-height: 200px; overflow-y: auto;"></div>
    </div>
  </div>

  <div id="uploadPreview">
    <button id="removeUpload" title="Upload entfernen">&times;</button>
    <img id="previewImage" src="" alt="Vorschau" />
  </div>

  <div class="inputBar">
    <label class="fileLabel" for="file" title="Datei anhängen">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M21.44 11.05L12.37 20.12a5.5 5.5 0 1 1-7.78-7.78l8.07-8.07a3.5 3.5 0 0 1 4.95 4.95L9.54 17.23a2 2 0 1 1-2.83-2.83l7.07-7.07" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
    </label>
    <button id="stickerBtn" title="Sticker">
      <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8zM8 11c-.55 0-1 .45-1 1s.45 1 1 1 1-.45 1-1-.45-1-1-1zm8 0c-.55 0-1 .45-1 1s.45 1 1 1 1-.45 1-1-.45-1-1-1zm-4 4c1.48 0 2.75-.81 3.45-2H8.55c.7 1.19 1.97 2 3.45 2z"/></svg>
    </button>
    <form id="chatForm" style="display:flex;flex:1;">
      <div class="inputWrap">
        <input type="text" id="msg" name="msg" placeholder="nachricht..." autocomplete="off" />
        <input type="file" id="file" name="file" />
      </div>
      <button id="sendBtn" type="submit">senden</button>
    </form>
  </div>
  </div>
</div>

<script>
const chatbox = document.getElementById('chatbox');
const form = document.getElementById('chatForm');
const msgInput = document.getElementById('msg');
const fileInput = document.getElementById('file');
const usernameDisplay = document.getElementById('usernameDisplay');
const editNameBtn = document.getElementById('editNameBtn');
const editPfpBtn = document.getElementById('editPfpBtn');
const deleteBtn = document.getElementById('deleteBtn');
const API = location.pathname;
let serverOffset = 0;
const EMOJI_LIST = ['tot', 'wow', 'sybau', 'deep', 'hahaha', 'cinema'];

const stickerBtn = document.getElementById('stickerBtn');
const stickerPanel = document.getElementById('stickerPanel');
const closeStickerPanelBtn = document.getElementById('closeStickerPanel');
const stickerGrid = document.getElementById('stickerGrid');
const stickerSearchInput = document.getElementById('stickerSearchInput');
const stickerSearchBtn = document.getElementById('stickerSearchBtn');
const stickerSearchGrid = document.getElementById('stickerGrid-search');

const uploadPreview = document.getElementById('uploadPreview');
const previewImage = document.getElementById('previewImage');
const removeUploadBtn = document.getElementById('removeUpload');

const scrollToBottomBtn = document.getElementById('scrollToBottom');

const DEFAULT_PFP = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%23888'%3E%3Cpath d='M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z'/%3E%3C/svg%3E";

// --- global click listener (for closing pickers) ---
document.addEventListener('click', (e) => {
    if (!e.target.closest('.react-btn')) {
        document.querySelectorAll('.emoji-picker').forEach(p => {
            p.style.display = 'none';
            // Reset position when closing
            p.style.bottom = '100%';
            p.style.top = 'auto';
        });
    }
});

// --- user profile ---
function getUsername() {
  let name = localStorage.getItem('chatName');
  if(!name){
    name = prompt('wähle einen namen: (du kannst ihn nur einmal pro stunde ändern)');
    if(!name || name.trim() === '') name = 'Gast';
    localStorage.setItem('chatName', name.trim());
    localStorage.setItem('lastNameChange', Date.now());
  }
  return name;
}
function getPfp() {
    return localStorage.getItem('chatPfp') || DEFAULT_PFP;
}

function showUsernameUI(){
  const name = getUsername();
  const pfp = getPfp();
  const pfpImg = document.createElement('img');
  pfpImg.src = pfp;
  pfpImg.style.width = '24px';
  pfpImg.style.height = '24px';
  pfpImg.style.borderRadius = '50%';
  pfpImg.style.objectFit = 'cover';
  usernameDisplay.innerHTML = `name: <b>${escapeHtml(name)}</b>`;
  usernameDisplay.prepend(pfpImg);
}

// edit name logic
editNameBtn.addEventListener('click', ()=>{
  const last = parseInt(localStorage.getItem('lastNameChange') || 0);
  const now = Date.now();
  const oneHour = 3600*1000;
  if(now - last < oneHour){
    const remain = Math.ceil((oneHour - (now - last))/60000);
    return alert('du kannst deinen namen erst in ' + remain + ' minuten ändern.');
  }
  const newName = prompt('neuer name:', localStorage.getItem('chatName') || '');
  if(!newName) return;
  localStorage.setItem('chatName', newName.trim());
  localStorage.setItem('lastNameChange', Date.now());
  showUsernameUI();
});

// edit pfp logic
editPfpBtn.addEventListener('click', () => {
    const newPfp = prompt('URL zum bild (zb https://bild.com/bild.png):', localStorage.getItem('chatPfp') || '');
    if (newPfp === null) return;
    if (newPfp === '') {
        localStorage.removeItem('chatPfp');
        showUsernameUI();
    } else if (newPfp.startsWith('http')) {
        localStorage.setItem('chatPfp', newPfp);
        showUsernameUI();
    } else {
        alert('ungültige url (muss mit https beginnen)');
    }
});


// --- thumbnail preview ---
fileInput.addEventListener('change', () => {
    if (fileInput.files.length > 0) {
        const file = fileInput.files[0];
        if (file.type.startsWith('image/')) {
            const reader = new FileReader();
            reader.onload = (e) => {
                previewImage.src = e.target.result;
                uploadPreview.classList.add('visible');
            };
            reader.readAsDataURL(file);
        } else {
            clearPreview();
        }
    } else {
        clearPreview();
    }
});

function clearPreview() {
    fileInput.value = '';
    uploadPreview.classList.remove('visible');
    previewImage.src = '';
}

removeUploadBtn.addEventListener('click', clearPreview);

// --- send ---
form.addEventListener('submit', function(e){
  e.preventDefault();
  const name = getUsername();
  const pfp = getPfp();
  const msg = msgInput.value || '';
  if (msg === '' && fileInput.files.length === 0) return;
  
  const fd = new FormData();
  fd.append('name', name);
  fd.append('pfp', pfp);
  fd.append('msg', msg);
  if(fileInput.files.length > 0) fd.append('file', fileInput.files[0]);
  fetch(API, { method:'POST', body: fd })
    .then(r=>r.json())
    .then(()=>{
      msgInput.value = '';
      clearPreview();
      loadChat(false);
    });
});

// --- delete ---
deleteBtn.addEventListener('click', ()=>{
  const code = prompt('Admincode:');
  if(!code) return;
  const fd = new FormData();
  fd.append('deleteCode', code);
  fetch(API, { method:'POST', body: fd })
    .then(r => r.text())
    .then(txt => {
      if(txt === 'deleted') alert('chats und dateien gelöscht!');
      else alert('falscher code!');
      loadChat();
    });
});

// --- sticker handling ---
const DEFAULT_STICKERS = [
    'https://media.giphy.com/media/v1.Y2lkPTc5MGI3NjExM3Yyb2MwaWI3Z2w4eWRhN2V3YnBocjZ0OWVjYTY1ZHMweW1qZzR6cSZlcD12MV9pbnRlcm5hbF9naWZfYnlfaWQmY3Q9Zw/3o7TKSjRrfEEnCSgBq/giphy.gif',
    'https://media.giphy.com/media/v1.Y2lkPTc5MGI3NjExM2MzM2I3d2s4aGZ6a3YxZzVvM2F6Z3ZlNG5xYmhnYjB2cWZ3NnBvciZlcD12MV9pbnRlcm5hbF9naWZfYnlfaWQmY3Q9Zw/3o7aboaC59dHIiRdy8/giphy.gif',
    'https://media.giphy.com/media/v1.Y2lkPTc5MGI3NjExcDBxNmJ0eXA2eG10M3Z6YTRtd2EzeHZ3cmNocGNoYjN5b3czcHNuNSZlcD12MV9pbnRlcm5hbF9naWZfYnlfaWQmY3Q9Zw/l3q2SaisWTrd2FwZ2/giphy.gif'
];

function getStickers() {
    const stored = localStorage.getItem('chatStickers');
    if (stored) {
        return JSON.parse(stored);
    }
    localStorage.setItem('chatStickers', JSON.stringify(DEFAULT_STICKERS));
    return DEFAULT_STICKERS;
}

function saveStickers(stickers) {
    localStorage.setItem('chatStickers', JSON.stringify(stickers));
    renderStickers();
}

function renderStickers() {
    const stickers = getStickers();
    stickerGrid.innerHTML = '';
    stickers.forEach(url => {
        const wrapper = document.createElement('div');
        wrapper.className = 'sticker-wrapper';
        const img = document.createElement('img');
        img.src = url;
        img.title = 'Sticker senden';
        img.loading = 'lazy';
        img.onclick = () => sendSticker(url);
        const removeBtn = document.createElement('button');
        removeBtn.className = 'remove-sticker-btn';
        removeBtn.innerHTML = '&times;';
        removeBtn.title = 'Sticker entfernen';
        removeBtn.onclick = (e) => {
            e.stopPropagation();
            removeSticker(url);
        };
        wrapper.appendChild(img);
        wrapper.appendChild(removeBtn);
        stickerGrid.appendChild(wrapper);
    });
}

function sendSticker(url) {
    const name = getUsername();
    const pfp = getPfp();
    const fd = new FormData();
    fd.append('name', name);
    fd.append('pfp', pfp);
    fd.append('sticker', url);

    fetch(API, { method:'POST', body: fd })
        .then(r=>r.json())
        .then(()=>{
            stickerPanel.classList.remove('visible');
            loadChat(false); // <-- FIX: War 'true'. Kein 'forceScroll' mehr.
        });
}

stickerBtn.addEventListener('click', () => {
    stickerPanel.classList.toggle('visible');
    if (stickerPanel.classList.contains('visible')) {
        renderStickers();
        // Focus first tab
        document.querySelector('.stickerTab[data-tab="myStickers"]').click();
    }
});
closeStickerPanelBtn.addEventListener('click', () => {
    stickerPanel.classList.remove('visible');
});


document.querySelectorAll('.stickerTab').forEach(tab => {
    tab.addEventListener('click', () => {
        document.querySelectorAll('.stickerTab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.stickerTabContent').forEach(c => c.classList.remove('active'));
        tab.classList.add('active');
        document.getElementById(tab.dataset.tab).classList.add('active');
    });
});

stickerSearchBtn.addEventListener('click', () => {
    const query = stickerSearchInput.value;
    if (!query) return;
    stickerSearchGrid.innerHTML = '<i>Lade...</i>';
    fetch(API + '?action=search_gif&q=' + encodeURIComponent(query))
        .then(r => r.json())
        .then(results => {
            stickerSearchGrid.innerHTML = '';
            if (results.error) {
                stickerSearchGrid.innerHTML = `<i>Fehler: ${results.error}</i>`;
                return;
            }
            if (results.length === 0) {
                stickerSearchGrid.innerHTML = '<i>Nichts gefunden.</i>';
                return;
            }
            results.forEach(gif => {
                const wrapper = document.createElement('div');
                wrapper.className = 'sticker-wrapper';
                const img = document.createElement('img');
                img.src = gif.preview; // Show still image as preview
                img.title = 'GIF senden';
                img.loading = 'lazy';
                img.onclick = () => sendSticker(gif.url); // Send the actual GIF
                wrapper.appendChild(img);
                stickerSearchGrid.appendChild(wrapper);
            });
        });
});
stickerSearchInput.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') stickerSearchBtn.click();
});


function addSticker(url) {
    if (!url || (!url.startsWith('http://') && !url.startsWith('https://'))) {
        return alert('Ungültige URL.');
    }
    let stickers = getStickers();
    if (stickers.includes(url)) {
        return alert('sticker ist schon in deinen favoriten.');
    }
    stickers.push(url);
    saveStickers(stickers);
    alert('sticker zu favoriten hinzugefügt!');
    // Switch to sticker tab
    stickerPanel.classList.add('visible');
    document.querySelector('.stickerTab[data-tab="myStickers"]').click();
}

function removeSticker(url) {
    if (!confirm('diesen sticker wirklich entfernen?')) return;
    let stickers = getStickers();
    const newStickers = stickers.filter(s => s !== url);
    saveStickers(newStickers);
}

// --- Reaction ---
function toggleReaction(msgId, emoji) {
    const name = getUsername();
    const fd = new FormData();
    fd.append('action', 'react');
    fd.append('id', msgId);
    fd.append('emoji', emoji);
    fd.append('name', name);
    fetch(API, { method:'POST', body: fd })
        .then(r => r.json())
        .then(loadChat);
}

// --- render ---
let lastMessages = [];
function renderMessages(data, forceScroll = false){
  if(data.server_time) serverOffset = data.server_time - Math.floor(Date.now()/1000);
  
  const myName = getUsername();
  const messages = (data.messages || []).filter(m => {
      if (m.type === 'bot') return true;
      if (!m.whisper_to) return true; // Public message
      return m.whisper_to === myName || m.name === myName; // To me, or from me
  });

  const asJson = JSON.stringify(messages);
  if(asJson === JSON.stringify(lastMessages)) return; // Nichts tun, wenn sich nichts geändert hat
  lastMessages = messages;
  
  // --- ROBUST SCROLL LOGIC ---
  // 1. Speichere, wo der benutzer *vor* dem neuladen war
  const oldScrollHeight = chatbox.scrollHeight;
  const oldScrollTop = chatbox.scrollTop;
  // (Wir fügen +100 Puffer hinzu, falls der Benutzer nicht *ganz* unten ist)
  const isAtBottom = (oldScrollTop + chatbox.clientHeight + 100) >= oldScrollHeight;
  
  // 2. Entscheide, ob wir nach dem neuladen nach unten scrollen sollen
  // (Nur wenn 'forceScroll' AN ist ODER der Benutzer bereits unten war)
  // HINWEIS: forceScroll ist jetzt beim Senden immer 'false'
  const shouldScroll = forceScroll || isAtBottom;
  // --- END ROBUST SCROLL LOGIC ---

  chatbox.innerHTML = '';
  messages.forEach(m=>{
    const div = document.createElement('div');
    div.classList.add('msg');
    if(m.type==='bot'){
        div.classList.add('bot');
        div.innerHTML = `<div>${escapeHtml(m.msg)}</div>`;
    } else {
        // --- User/Me Message ---
        const isMe = m.name === myName;
        div.classList.add(isMe ? 'me' : 'user');
        if (m.whisper_to) {
            div.classList.add('whisper');
        }

        const meta = document.createElement('div');
        meta.className = 'meta';
        const pfp = document.createElement('img');
        pfp.className = 'pfp';
        pfp.src = m.pfp || DEFAULT_PFP; // Use message PFP, fallback to default
        pfp.onerror = () => { pfp.src = DEFAULT_PFP; }; // Fallback on 404
        
        const dt = new Date((m.time+serverOffset)*1000);
        
        if (!isMe) {
            const dmBtn = document.createElement('span');
            dmBtn.className = 'dm-btn';
            dmBtn.textContent = 'DM';
            dmBtn.title = `Privatnachricht an ${m.name}`;
            dmBtn.onclick = () => {
                msgInput.value = `/w ${m.name} `;
                msgInput.focus();
            };
            meta.appendChild(dmBtn);
        }

        meta.append(`${m.name} • ${dt.toLocaleTimeString()}`);
        if (isMe) meta.appendChild(pfp);
        else meta.prepend(pfp);

        const inner = document.createElement('div');
        inner.className = 'msg-inner';
        inner.appendChild(meta);

        if(m.msg && m.msg.length){
          const p = document.createElement('div');
          let msgHtml = escapeHtml(m.msg).replace(/\n/g,'<br>');
          // Mention highlighting
          const mentionRegex = new RegExp(`@${escapeRegExp(myName)}(\\b|$)`, 'gi');
          msgHtml = msgHtml.replace(mentionRegex, `<strong class="mention">@${myName}</strong>`);
          p.innerHTML = msgHtml;
          inner.appendChild(p);
        }
        
        if(m.sticker){
            const img=document.createElement('img');
            img.src=m.sticker;
            img.className='sticker';
            img.title = 'sticker hinzufügen';
            img.onclick = () => addSticker(m.sticker);
            inner.appendChild(img);
        }
        
        if(m.file){
          const ext=(m.file.path.split('.').pop()||'').toLowerCase();
          if(['jpg','jpeg','png','gif','webp','webm'].includes(ext)){
            const img=document.createElement('img');
            img.src=m.file.path;img.className='thumb';
            inner.appendChild(img);
          }else if(['mp4','webm','ogg'].includes(ext)){
            const v=document.createElement('video');
            v.src=m.file.path;v.controls=true;v.className='thumb';
            inner.appendChild(v);
          }else{
            const a=document.createElement('a');
            a.href=m.file.path;a.target='_blank';a.textContent=m.file.name;
            inner.appendChild(a);
          }
        }
        
        // --- Reactions ---
        const reactBtn = document.createElement('div');
        reactBtn.className = 'react-btn';
        reactBtn.innerHTML = '<span class="react-btn-inner">reagieren</span>';
        
        const emojiPicker = document.createElement('div');
        emojiPicker.className = 'emoji-picker';
        EMOJI_LIST.forEach(emoji => {
            const span = document.createElement('span');
            span.textContent = emoji;
            span.onclick = (e) => {
                e.stopPropagation();
                toggleReaction(m.id, emoji);
                emojiPicker.style.display = 'none';
            };
            emojiPicker.appendChild(span);
        });
        
        reactBtn.appendChild(emojiPicker);
        
        // Picker position logic
        reactBtn.onclick = (e) => {
            e.stopPropagation();
            // Close all other pickers
            document.querySelectorAll('.emoji-picker').forEach(p => {
                if (p !== emojiPicker) {
                    p.style.display = 'none';
                    // Reset their positions
                    p.style.bottom = '100%';
                    p.style.top = 'auto';
                }
            });
            
            // Toggle this one
            const currentDisplay = window.getComputedStyle(emojiPicker).display;
            const isNowOpen = currentDisplay === 'none';
            
            if (isNowOpen) {
                // Set to default position (above) *before* showing
                emojiPicker.style.bottom = '100%';
                emojiPicker.style.top = 'auto';
                emojiPicker.style.display = 'grid';

                // Check position
                const pickerRect = emojiPicker.getBoundingClientRect();
                const chatboxRect = chatbox.getBoundingClientRect();
                
                // Check if it's going off the top
                if (pickerRect.top < chatboxRect.top) {
                    // Not enough space above, flip it below
                    emojiPicker.style.bottom = 'auto';
                    emojiPicker.style.top = '100%';
                }
            } else {
                emojiPicker.style.display = 'none';
                // Reset position on close
                emojiPicker.style.bottom = '100%';
                emojiPicker.style.top = 'auto';
            }
        };

        inner.appendChild(reactBtn);
        div.appendChild(inner);

        if (m.reactions && Object.keys(m.reactions).length > 0) {
            const reactionsWrap = document.createElement('div');
            reactionsWrap.className = 'reactions-wrap';
            for (const [emoji, names] of Object.entries(m.reactions)) {
                if (names.length === 0) continue;
                const reaction = document.createElement('div');
                reaction.className = 'reaction';
                reaction.textContent = `${emoji} ${names.length}`;
                reaction.title = names.join(', ');
                if (names.includes(myName)) {
                    reaction.classList.add('me');
                }
                reaction.onclick = () => toggleReaction(m.id, emoji);
                reactionsWrap.appendChild(reaction);
            }
            div.appendChild(reactionsWrap);
        }
    }
    
    if(m.type==='bot'&&m.delete_at){
      const countdown=document.createElement('div');
      countdown.style.marginTop='6px';
      countdown.style.fontWeight='700';
      countdown.style.color='#ffde59';
      countdown.className='countdown';
      div.appendChild(countdown);
      function updateCountdown(){
        const serverNow=Math.floor(Date.now()/1000)+serverOffset;
        const left=m.delete_at-serverNow;
        countdown.textContent='chat wird gelöscht in '+(left>0?left:0)+'s';
      }
      updateCountdown();
      setInterval(updateCountdown,500);
    }
    chatbox.appendChild(div);
  });
  
  if (messages.length === 0) {
    chatbox.innerHTML = '<i style="color:#777">noch keine nachrichten...</i>';
  }
  
  // --- ROBUST SCROLL RESTORE ---
  // 3. Wende die Scroll-Position *nach* dem Neuaufbau des DOM an.
  const newScrollHeight = chatbox.scrollHeight;
  if (shouldScroll) {
      // Fall 1: Nach unten scrollen.
      // WICHTIG: setTimeout(0) ist nötig, damit der Browser
      // die CSS-Animation und die neue Höhe rendern kann,
      // *bevor* wir scrollen. Das behebt den "scrollt-nur-irgendwohin"-Bug.
      setTimeout(() => {
          chatbox.scrollTop = chatbox.scrollHeight;
      }, 0);
  } else {
      // Fall 2: Alte Position wiederherstellen (damit es nicht nach oben springt)
      chatbox.scrollTop = oldScrollTop + (newScrollHeight - oldScrollHeight);
  }
  // --- END ROBUST SCROLL RESTORE ---
}

function loadChat(forceScroll = false){
  fetch(API+'?action=load')
  .then(r=>r.json())
  .then(data=>renderMessages(data, forceScroll));
}

function escapeHtml(unsafe){
  return unsafe.replace(/&/g,"&amp;")
               .replace(/</g,"&lt;")
               .replace(/>/g,"&gt;")
               .replace(/"/g,"&quot;")
               .replace(/'/g,"&#039;");
}
function escapeRegExp(string) {
  return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

// --- Scroll to Bottom Button Logic ---
chatbox.addEventListener('scroll', () => {
    // Show button if user is scrolled up more than 200px
    if (chatbox.scrollTop + chatbox.clientHeight + 200 < chatbox.scrollHeight) {
        scrollToBottomBtn.style.display = 'block';
    } else {
        scrollToBottomBtn.style.display = 'none';
    }
});
scrollToBottomBtn.addEventListener('click', () => {
    chatbox.scrollTo({
        top: chatbox.scrollHeight,
        behavior: 'smooth'
    });
});


showUsernameUI();
loadChat();
setInterval(loadChat,1500); // Polling every 1.5 seconds
</script>
</body>
</html>
