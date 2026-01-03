<?php
function listDirectory($dir, $indent = 0) {
    $ignore = ['.', '..', '.git', 'node_modules', 'vendor', '.idea'];
    
    $files = scandir($dir);
    foreach ($files as $file) {
        if (in_array($file, $ignore)) continue;
        
        $path = $dir . '/' . $file;
        echo str_repeat('│   ', $indent) . "├── " . $file . "\n";
        
        if (is_dir($path)) {
            listDirectory($path, $indent + 1);
        }
    }
}

echo "Project Structure:\n";
listDirectory(__DIR__);
?>