<?php
/**
 * OURCR ONLINE - Contact Form AJAX Processor
 */

define('OURCR_ONLINE', true);
require_once dirname(__DIR__) . '/config/config.php';

header('Content-Type: application/json');

if (!isPost()) {
    jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
}

try {
    // Rate Limiting by IP
    $ip = getClientIP();
    $limit = rateLimit($ip, 'contact_submit', 3, 300); // Max 3 contact submissions per 5 minutes
    if (!$limit['allowed']) {
        jsonResponse(['success' => false, 'message' => 'Too many messages. Please try again in 5 minutes.'], 429);
    }

    $name    = sanitizeString(post('name'));
    $email   = sanitizeEmail(post('email'));
    $subject = sanitizeString(post('subject'));
    $message = sanitizeString(post('message'));

    if (empty($name) || empty($email) || empty($subject) || empty($message)) {
        jsonResponse(['success' => false, 'message' => 'All fields are required.'], 400);
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(['success' => false, 'message' => 'Invalid email address.'], 400);
    }

    // Write contact inquiry to system logs
    writeLog('system', 'info', "Contact message from $name ($email): [$subject] $message");

    // Attempt sending mock email to admin
    $adminEmail = setting('site_email', 'admin@ourcr.online');
    $emailBody = "<h3>New Contact Message</h3>
                  <p><strong>Name:</strong> $name</p>
                  <p><strong>Email:</strong> $email</p>
                  <p><strong>Subject:</strong> $subject</p>
                  <p><strong>Message:</strong></p>
                  <p>$message</p>";
    
    // In production, we'd send an email to the admin
    // sendMail($adminEmail, 'OURCR Admin', "Contact: $subject", $emailBody);

    jsonResponse([
        'success' => true,
        'message' => 'Thank you! Your message has been received and our support team will contact you shortly.'
    ]);

} catch (Exception $e) {
    writeLog('error', 'error', 'Contact API error: ' . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'An error occurred while processing your message.'], 500);
}
