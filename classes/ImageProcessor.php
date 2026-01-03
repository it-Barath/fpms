<?php
// classes/ImageProcessor.php

class ImageProcessor {
    
    /**
     * Create thumbnail from source image
     */
    public function createThumbnail($source_path, $destination_path, $width, $height) {
        // Check if GD is installed
        if (!extension_loaded('gd') || !function_exists('gd_info')) {
            // If GD is not available, just copy the file
            copy($source_path, $destination_path);
            return true;
        }
        
        // Get image info
        $image_info = @getimagesize($source_path);
        if (!$image_info) {
            // If can't read image, copy as is
            copy($source_path, $destination_path);
            return true;
        }
        
        // Create image from source based on type
        $source_image = null;
        switch ($image_info[2]) {
            case IMAGETYPE_JPEG:
                $source_image = @imagecreatefromjpeg($source_path);
                break;
            case IMAGETYPE_PNG:
                $source_image = @imagecreatefrompng($source_path);
                break;
            case IMAGETYPE_GIF:
                $source_image = @imagecreatefromgif($source_path);
                break;
            default:
                // Unsupported type, copy as is
                copy($source_path, $destination_path);
                return true;
        }
        
        if (!$source_image) {
            // If image creation failed, copy as is
            copy($source_path, $destination_path);
            return true;
        }
        
        // Get original dimensions
        $original_width = imagesx($source_image);
        $original_height = imagesy($source_image);
        
        // Calculate thumbnail dimensions (crop to square)
        $src_x = 0;
        $src_y = 0;
        $src_w = $original_width;
        $src_h = $original_height;
        
        if ($original_width > $original_height) {
            $src_x = intval(($original_width - $original_height) / 2);
            $src_w = $original_height;
        } else {
            $src_y = intval(($original_height - $original_width) / 2);
            $src_h = $original_width;
        }
        
        // Create thumbnail image
        $thumb_image = imagecreatetruecolor($width, $height);
        
        // Fill with white background
        $white = imagecolorallocate($thumb_image, 255, 255, 255);
        imagefill($thumb_image, 0, 0, $white);
        
        // Resize and crop
        imagecopyresampled($thumb_image, $source_image, 0, 0, $src_x, $src_y, 
                          $width, $height, $src_w, $src_h);
        
        // Save thumbnail as JPEG
        imagejpeg($thumb_image, $destination_path, 85);
        
        // Clean up
        imagedestroy($source_image);
        imagedestroy($thumb_image);
        
        return true;
    }
    
    /**
     * Add watermark to image file
     */
    public function addWatermark($image_path, $watermark_text, $font_size = 12) {
        // Check if GD is installed
        if (!extension_loaded('gd') || !function_exists('gd_info')) {
            return false;
        }
        
        // Get image info
        $image_info = @getimagesize($image_path);
        if (!$image_info) {
            return false;
        }
        
        // Create image from file based on type
        $image = null;
        switch ($image_info[2]) {
            case IMAGETYPE_JPEG:
                $image = @imagecreatefromjpeg($image_path);
                break;
            case IMAGETYPE_PNG:
                $image = @imagecreatefrompng($image_path);
                break;
            case IMAGETYPE_GIF:
                $image = @imagecreatefromgif($image_path);
                break;
            default:
                return false;
        }
        
        if (!$image) {
            return false;
        }
        
        // Set watermark color (semi-transparent white)
        $color = imagecolorallocatealpha($image, 255, 255, 255, 60);
        
        // Get image dimensions
        $width = imagesx($image);
        $height = imagesy($image);
        
        // Use a built-in font if TTF is not available
        if (function_exists('imagettftext')) {
            // Try to find a TTF font
            $font_paths = [
                '../../../assets/fonts/arial.ttf',
                '../../../assets/fonts/Arial.ttf',
                '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
                '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
                'C:/Windows/Fonts/arial.ttf'
            ];
            
            $font = null;
            foreach ($font_paths as $font_path) {
                if (file_exists($font_path)) {
                    $font = $font_path;
                    break;
                }
            }
            
            if ($font) {
                // Calculate text bounding box
                $bbox = imagettfbbox($font_size, 0, $font, $watermark_text);
                $text_width = $bbox[2] - $bbox[0];
                $text_height = $bbox[1] - $bbox[7];
                
                // Position at bottom right with padding
                $x = $width - $text_width - 10;
                $y = $height - 10;
                
                // Add text watermark
                imagettftext($image, $font_size, 0, $x, $y, $color, $font, $watermark_text);
            } else {
                // Use built-in font if TTF not found
                $x = $width - (strlen($watermark_text) * imagefontwidth(5)) - 10;
                $y = $height - imagefontheight(5) - 10;
                imagestring($image, 5, $x, $y, $watermark_text, $color);
            }
        } else {
            // Use built-in font
            $x = $width - (strlen($watermark_text) * imagefontwidth(5)) - 10;
            $y = $height - imagefontheight(5) - 10;
            imagestring($image, 5, $x, $y, $watermark_text, $color);
        }
        
        // Save image back
        switch ($image_info[2]) {
            case IMAGETYPE_JPEG:
                imagejpeg($image, $image_path, 85);
                break;
            case IMAGETYPE_PNG:
                imagepng($image, $image_path);
                break;
            case IMAGETYPE_GIF:
                imagegif($image, $image_path);
                break;
        }
        
        // Clean up
        imagedestroy($image);
        
        return true;
    }
    
    /**
     * Process profile image (simplified version)
     */
    public function processProfileImage($source_path, $destination_path) {
        // Just copy and resize if needed
        return $this->createThumbnail($source_path, $destination_path, 400, 400);
    }
}
?>