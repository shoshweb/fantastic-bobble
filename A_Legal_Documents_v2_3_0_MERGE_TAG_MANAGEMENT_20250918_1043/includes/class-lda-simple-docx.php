<?php
/**
 * Simple DOCX Processing without PHPWord
 * Uses basic ZIP manipulation for merge tag replacement
 */

if (!defined('ABSPATH')) {
    exit;
}

class LDA_SimpleDOCX {
    
    /**
     * Process merge tags in DOCX file
     */
    public static function processMergeTags($template_path, $merge_data, $output_path) {
        try {
            // Create a copy of the template
            if (!copy($template_path, $output_path)) {
                throw new Exception('Failed to copy template file');
            }
            
            // Open the DOCX as a ZIP file
            $zip = new ZipArchive();
            if ($zip->open($output_path) !== TRUE) {
                throw new Exception('Failed to open DOCX file');
            }
            
            // Read the main document XML
            $document_xml = $zip->getFromName('word/document.xml');
            if ($document_xml === false) {
                throw new Exception('Failed to read document.xml');
            }
            
            // Process merge tags
            $processed_xml = self::replaceMergeTags($document_xml, $merge_data);
            
            // Debug: Log XML content before and after processing
            LDA_Logger::log("Original XML length: " . strlen($document_xml));
            LDA_Logger::log("Processed XML length: " . strlen($processed_xml));
            
            // Check if any changes were made
            if ($document_xml === $processed_xml) {
                LDA_Logger::warn("No changes detected in XML content - merge tags may not have been found");
            } else {
                LDA_Logger::log("XML content was modified during merge tag processing");
            }
            
            // Write back the processed XML
            if ($zip->addFromString('word/document.xml', $processed_xml) === false) {
                throw new Exception('Failed to write processed document.xml');
            }
            
            $zip->close();
            
            // Verify the file was created and has content
            if (!file_exists($output_path)) {
                throw new Exception('Output file was not created');
            }
            
            $file_size = filesize($output_path);
            LDA_Logger::log("Output file created successfully. Size: " . $file_size . " bytes");
            
            return array('success' => true);
            
        } catch (Exception $e) {
            LDA_Logger::error("Simple DOCX processing failed: " . $e->getMessage());
            return array('success' => false, 'error' => $e->getMessage());
        }
    }
    
    /**
     * Replace merge tags in XML content
     */
    private static function replaceMergeTags($xml_content, $merge_data) {
        // Safety check for merge_data
        if (!is_array($merge_data)) {
            LDA_Logger::warn("Merge data is not an array, skipping merge tag replacement");
            return $xml_content;
        }
        
        LDA_Logger::log("Starting merge tag replacement with " . count($merge_data) . " merge tags");
        
        // Debug: Check what merge tags are actually in the XML
        preg_match_all('/\{\$[^}]+\}/', $xml_content, $dollar_matches);
        preg_match_all('/\{[^}]+\}/', $xml_content, $all_matches);
        
        LDA_Logger::log("Found " . count($dollar_matches[0]) . " {\$TAG} patterns in XML: " . implode(', ', $dollar_matches[0]));
        LDA_Logger::log("Found " . count($all_matches[0]) . " total {TAG} patterns in XML: " . implode(', ', array_slice($all_matches[0], 0, 10)));
        
        // Debug: Look for split merge tags across XML elements
        preg_match_all('/\{\$[^<]*<[^>]*>[^}]*\}/', $xml_content, $split_matches);
        if (count($split_matches[0]) > 0) {
            LDA_Logger::log("Found " . count($split_matches[0]) . " split merge tags across XML elements: " . implode(', ', $split_matches[0]));
        }
        
        $replacements_made = 0;
        
        // WEBMERGE COMPATIBLE APPROACH: Extract text, process, then reconstruct
        $xml_content = self::processMergeTagsWebmergeStyle($xml_content, $merge_data, $replacements_made);
        
        // Process {VARIABLE_NAME} format (fallback)
        foreach ($merge_data as $key => $value) {
            $pattern = '/\{' . preg_quote($key, '/') . '\}/';
            $before = $xml_content;
            $xml_content = preg_replace($pattern, htmlspecialchars($value, ENT_XML1, 'UTF-8'), $xml_content);
            if ($before !== $xml_content) {
                $replacements_made++;
                LDA_Logger::log("Replaced {$key} with: " . $value);
            }
        }
        
        LDA_Logger::log("Merge tag replacement completed. Total replacements made: " . $replacements_made);
        
        return $xml_content;
    }
    
    
    /**
     * Process modifiers for merge tags
     */
    private static function processModifiers($value, $key, &$xml_content, $pattern) {
        // Find all matches for this pattern
        preg_match_all($pattern, $xml_content, $matches);
        
        foreach ($matches[0] as $match) {
            // Extract the modifier part
            $modifier_part = substr($match, strlen('{$' . $key . '|'), -1); // Remove {$key| and }
            
            $processed_value = $value;
            
            // Handle different modifiers
            if (strpos($modifier_part, 'upper') !== false) {
                $processed_value = strtoupper($processed_value);
            }
            
            if (strpos($modifier_part, 'lower') !== false) {
                $processed_value = strtolower($processed_value);
            }
            
            if (strpos($modifier_part, 'phone_format') !== false) {
                // Extract phone format pattern
                if (preg_match('/phone_format:"([^"]+)"/', $modifier_part, $format_matches)) {
                    $format = $format_matches[1];
                    $processed_value = self::formatPhone($processed_value, $format);
                }
            }
            
            if (strpos($modifier_part, 'date_format') !== false) {
                // Extract date format pattern
                if (preg_match('/date_format:"([^"]+)"/', $modifier_part, $format_matches)) {
                    $format = $format_matches[1];
                    $processed_value = self::formatDate($processed_value, $format);
                }
            }
            
            if (strpos($modifier_part, 'replace') !== false) {
                // Extract replace pattern
                if (preg_match('/replace:"([^"]+)":"([^"]*)"/', $modifier_part, $replace_matches)) {
                    $search = $replace_matches[1];
                    $replace = $replace_matches[2];
                    $processed_value = str_replace($search, $replace, $processed_value);
                }
            }
            
            // Replace the match with processed value
            $xml_content = str_replace($match, htmlspecialchars($processed_value, ENT_XML1, 'UTF-8'), $xml_content);
        }
        
        return $processed_value;
    }
    
    /**
     * Format phone number
     */
    private static function formatPhone($phone, $format) {
        // Remove all non-digits
        $digits = preg_replace('/\D/', '', $phone);
        
        // Apply format pattern
        $formatted = $format;
        $digit_index = 0;
        
        for ($i = 0; $i < strlen($format); $i++) {
            if ($format[$i] === '%' && $i + 1 < strlen($format)) {
                $next_char = $format[$i + 1];
                if (is_numeric($next_char) && $digit_index < strlen($digits)) {
                    $formatted = substr_replace($formatted, $digits[$digit_index], $i, 2);
                    $digit_index++;
                }
            }
        }
        
        return $formatted;
    }
    
    /**
     * Format date
     */
    private static function formatDate($date, $format) {
        if (empty($date)) {
            return '';
        }
        
        // Try to parse the date
        $timestamp = strtotime($date);
        if ($timestamp === false) {
            return $date; // Return original if can't parse
        }
        
        // Convert format patterns
        $format = str_replace('d', 'j', $format); // Day without leading zeros
        $format = str_replace('F', 'F', $format); // Full month name
        $format = str_replace('Y', 'Y', $format); // Full year
        
        return date($format, $timestamp);
    }
    
    /**
     * Check if DOCX processing is available
     */
    public static function isAvailable() {
        return class_exists('ZipArchive');
    }
}
