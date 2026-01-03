<?php
// classes/ImageProcessor.php

class ImageProcessor {
    
    /**
     * Process profile image with cropping and watermark
     */
    public function processProfileImage($source_path, $destination_path) {
        // Get image info
        $image_info = getimagesize($source_path);
        if (!$image_info) {
            throw new Exception('Invalid image file');
        }
        
        // Create image from source
        $source_image = $this->createImageFromFile($source_path, $image_info[2]);
        
        // Get original dimensions
        $original_width = imagesx($source_image);
        $original_height = imagesy($source_image);
        
        // Calculate new dimensions (maintain aspect ratio, max 800px)
        $max_size = 800;
        if ($original_width > $original_height) {
            $new_width = $max_size;
            $new_height = intval($original_height * ($max_size / $original_width));
        } else {
            $new_height = $max_size;
            $new_width = intval($original_width * ($max_size / $original_height));
        }
        
        // Create new image
        $new_image = imagecreatetruecolor($new_width, $new_height);
        
        // Preserve transparency for PNG/GIF
        if ($image_info[2] == IMAGETYPE_PNG || $image_info[2] == IMAGETYPE_GIF) {
            imagealphablending($new_image, false);
            imagesavealpha($new_image, true);
            $transparent = imagecolorallocatealpha($new_image, 255, 255, 255, 127);
            imagefilledrectangle($new_image, 0, 0, $new_width, $new_height, $transparent);
        }
        
        // Resize image
        imagecopyresampled($new_image, $source_image, 0, 0, 0, 0, 
                          $new_width, $new_height, $original_width, $original_height);
        
        // Add watermark
        $this->addWatermarkResource($new_image, 'SYSCGAA', 24);
        
        // Save image
        $this->saveImage($new_image, $destination_path, IMAGETYPE_JPEG, 80);
        
        // Clean up
        imagedestroy($source_image);
        imagedestroy($new_image);
        
        return true;
    }
    
    /**
     * Create thumbnail from source image
     */
    public function createThumbnail($source_path, $destination_path, $width, $height) {
        // Get image info
        $image_info = getimagesize($source_path);
        if (!$image_info) {
            throw new Exception('Invalid image file');
        }
        
        // Create image from source
        $source_image = $this->createImageFromFile($source_path, $image_info[2]);
        
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
        
        // Preserve transparency for PNG/GIF
        if ($image_info[2] == IMAGETYPE_PNG || $image_info[2] == IMAGETYPE_GIF) {
            imagealphablending($thumb_image, false);
            imagesavealpha($thumb_image, true);
            $transparent = imagecolorallocatealpha($thumb_image, 255, 255, 255, 127);
            imagefilledrectangle($thumb_image, 0, 0, $width, $height, $transparent);
        }
        
        // Resize and crop
        imagecopyresampled($thumb_image, $source_image, 0, 0, $src_x, $src_y, 
                          $width, $height, $src_w, $src_h);
        
        // Save thumbnail
        $this->saveImage($thumb_image, $destination_path, IMAGETYPE_JPEG, 70);
        
        // Clean up
        imagedestroy($source_image);
        imagedestroy($thumb_image);
        
        return true;
    }
    
    /**
     * Add watermark to image file
     */
    public function addWatermark($image_path, $watermark_text, $font_size = 12) {
        // Get image info
        $image_info = getimagesize($image_path);
        if (!$image_info) {
            throw new Exception('Invalid image file');
        }
        
        // Create image from file
        $image = $this->createImageFromFile($image_path, $image_info[2]);
        
        // Add watermark
        $this->addWatermarkResource($image, $watermark_text, $font_size);
        
        // Save image
        $this->saveImage($image, $image_path, $image_info[2], 80);
        
        // Clean up
        imagedestroy($image);
        
        return true;
    }
    
    /**
     * Add watermark to image resource
     */
    private function addWatermarkResource($image, $watermark_text, $font_size) {
        // Set watermark color (semi-transparent white)
        $color = imagecolorallocatealpha($image, 255, 255, 255, 60);
        
        // Get image dimensions
        $width = imagesx($image);
        $height = imagesy($image);
        
        // Calculate position (bottom right)
        $padding = 10;
        $x = $width - (strlen($watermark_text) * ($font_size * 0.6)) - $padding;
        $y = $height - $padding;
        
        // Add text watermark
        imagettftext($image, $font_size, 0, $x, $y, $color, 
                    '../../../assets/fonts/arial.ttf', $watermark_text);
    }
    
    /**
     * Create image from file based on type
     */
    private function createImageFromFile($path, $type) {
        switch ($type) {
            case IMAGETYPE_JPEG:
                return imagecreatefromjpeg($path);
            case IMAGETYPE_PNG:
                return imagecreatefrompng($path);
            case IMAGETYPE_GIF:
                return imagecreatefromgif($path);
            default:
                throw new Exception('Unsupported image type');   
        }
    }
      
    /**
     * Save image to file
     */
    private function saveImage($image, $path, $type, $quality = 80) {
        switch ($type) {
            case IMAGETYPE_JPEG:
                return imagejpeg($image, $path, $quality);
            case IMAGETYPE_PNG:
                return imagepng($image, $path, intval($quality / 10));
            case IMAGETYPE_GIF:
                return imagegif($image, $path);
            default:
                throw new Exception('Unsupported image type for saving');
        }
    }
}
?>