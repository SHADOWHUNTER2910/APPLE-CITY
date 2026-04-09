<?php
// Simple PHP wrapper to serve index.html with proper headers
// This ensures no CSP issues and proper content type

// Set headers
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// No CSP restrictions for local development
// If you want to add CSP for production, uncomment and modify:
// header("Content-Security-Policy: default-src 'self' 'unsafe-inline' 'unsafe-eval'; img-src 'self' data:;");

// Serve the index.html file
readfile(__DIR__ . '/index.html');
