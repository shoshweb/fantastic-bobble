<?php
/**
 * Simple Merge Engine for Legal Document Automation
 * No external dependencies - uses only WordPress built-in functions
 */

if (!defined('ABSPATH')) {
    exit;
}

class LDA_MergeEngine {

    private $settings;

    public function __construct($settings = array()) {
        $this->settings = $settings;
    }

    /**
     * Merge data into DOCX template
     */
    public function mergeDocument($template_path, $merge_data, $output_path) {
        try {
            LDA_Logger::log("Starting document merge process");
            LDA_Logger::log("Template: {$template_path}");
            LDA_Logger::log("Output: {$output_path}");
            
            // Check if template exists
            if (!file_exists($template_path)) {
                throw new Exception("Template file not found: {$template_path}");
            }
            
            // Use Webmerge-compatible DOCX processing
            $result = LDA_WebmergeDOCX::processMergeTags($template_path, $output_path, $merge_data);
            
            if ($result['success']) {
                LDA_Logger::log("Document merge completed successfully");
                return array('success' => true, 'file_path' => $output_path);
            } else {
                throw new Exception("DOCX processing failed: " . $result['error']);
            }
            
        } catch (Exception $e) {
            LDA_Logger::error("Document merge failed: " . $e->getMessage());
            return array('success' => false, 'error' => $e->getMessage());
        }
    }

    /**
     * Merge document and generate PDF
     */
    public function mergeDocumentWithPdf($template_path, $merge_data, $docx_output_path, $pdf_output_path) {
        try {
            // First merge the DOCX document
            $docx_result = $this->mergeDocument($template_path, $merge_data, $docx_output_path);
            if (!$docx_result['success']) {
                return $docx_result;
            }

            // Generate PDF version (optional)
            if (isset($this->settings['enable_pdf_output']) && $this->settings['enable_pdf_output']) {
                $pdf_handler = new LDA_PDFHandler($this->settings);
                $pdf_result = $pdf_handler->convertDocxToPdf($docx_output_path, $pdf_output_path);

                if (!$pdf_result['success']) {
                    LDA_Logger::warn("PDF generation failed, but DOCX was created successfully: " . $pdf_result['error']);
                    return array(
                        'success' => true,
                        'docx_path' => $docx_output_path,
                        'pdf_path' => null,
                        'pdf_error' => $pdf_result['error'],
                        'message' => 'DOCX created successfully, but PDF generation failed'
                    );
                }

                LDA_Logger::log("Document and PDF generated successfully");
                return array(
                    'success' => true,
                    'docx_path' => $docx_output_path,
                    'pdf_path' => $pdf_output_path,
                    'message' => 'Both DOCX and PDF generated successfully'
                );
            }

            return array(
                'success' => true,
                'docx_path' => $docx_output_path,
                'pdf_path' => null,
                'message' => 'DOCX generated successfully (PDF disabled)'
            );

        } catch (Exception $e) {
            LDA_Logger::error("Document merge with PDF failed: " . $e->getMessage());
            return array('success' => false, 'error' => $e->getMessage());
        }
    }

    /**
     * Validate template file
     */
    public function validateTemplate($template_path) {
        try {
            if (!file_exists($template_path)) {
                return array('success' => false, 'message' => 'Template file not found');
            }

            if (!is_readable($template_path)) {
                return array('success' => false, 'message' => 'Template file is not readable');
            }

            // Check if it's a valid DOCX file
            $zip = new ZipArchive();
            if ($zip->open($template_path) !== TRUE) {
                return array('success' => false, 'message' => 'Invalid DOCX file format');
            }

            // Check for required files
            if ($zip->locateName('word/document.xml') === false) {
                $zip->close();
                return array('success' => false, 'message' => 'Invalid DOCX structure - missing document.xml');
            }

            $zip->close();
            return array('success' => true, 'message' => 'Template is valid');

        } catch (Exception $e) {
            return array('success' => false, 'message' => 'Template validation error: ' . $e->getMessage());
        }
    }
}
