<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: sans-serif;  padding: 20px; }
        .log-box { border: 1px solid #ccc; padding: 20px; border-radius: 5px; }
        .title { font-size: 20px; font-weight: bold; margin-bottom: 10px; }
        .timestamp, .level { font-size: 14px; }
        .text-info { color: #007BFF; }
        .text-success { color: green; }
    </style>
</head>
<body>
    <div class="log-box">
        <div class="title">🚨 {{ env("APP_NAME") }} Log Alert</div>
        <div class="message">
         <span class="text-success"> [{{ $timestamp }}] </span> 
         <span class="text-info">{{ $level }}: </span> 
         <span>{{ $messageText }}</span>
        </div>
       
    </div>
</body>
</html>
