<?php
// Copy this file to config.php (same folder) and fill in real values on the server.
// config.php is gitignored - never commit real API keys or tokens.
//
// Why putenv() instead of .htaccess SetEnv or a .env file: shared/cPanel hosting
// PHP handlers vary (mod_php, suPHP, PHP-FPM), and SetEnv does not reliably reach
// getenv() on all of them. putenv() inside a plain PHP file always works.

putenv('GROQ_API_KEY=');
putenv('GEMINI_API_KEY=');
putenv('ADMIN_TOKEN=');

// Optional overrides - uncomment only if you need to change the defaults.
// putenv('GROQ_MODEL=llama-3.3-70b-versatile');
// putenv('GEMINI_MODEL=gemini-flash-latest');
// putenv('USE_AI_GENERATION=0'); // 0 = always use the quick template generator, skip Groq/Gemini
