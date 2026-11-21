<?php

namespace Zero\Module;

use \Zero\Core\Module;
use \Zero\Core\Request;
use \Zero\Core\Console;
use \Zero\Lib\Mail\Mail;
use \Zero\Core\Attribute\{AllowedMethods, RequiredParams, RequireLogin};

/**
 * AccessRequest Module
 * Handles user requests for access to restricted resources
 */
class AccessRequest extends Module {

    public function __construct() {
        parent::__construct();
    }

    /**
     * Display access request form for a specific permission
     * GET /access-request/permission?p=permission.key
     */
    #[RequiredParams(['p'])]
    public function permission() {
        $permissionKey = $_GET['p'];
        $requestedUrl = $_SERVER['HTTP_REFERER'] ?? '/';

        // Check if user is logged in
        $isLoggedIn = \Zero\Core\Session::isLoggedIn();

        if (!$isLoggedIn) {
            // Show login prompt instead of form
            $this->body = "
                <div class='content-wrapper mx-auto surface-medium p-2 border-radius-8 mt-3'>
                    <div class='text-center mb-2'>
                        <span class='material-symbols-outlined icon-xl text-warning'>lock</span>
                        <h2 class='mt-1'>Access Required</h2>
                        <p>You don't have permission to access this resource.</p>
                        <p class='mt-1'>You must be logged in to request access from the administrator.</p>
                    </div>
                    <div class='text-center mt-2'>
                        <a href='/user/login' class='btn-primary'>
                            <span class='material-symbols-outlined icon-before-text'>login</span>
                            Log in to Submit Access Request
                        </a>
                    </div>
                </div>
            ";
        } else {
            // Show access request form
            $userName = $_SESSION['user_name'] ?? $_SESSION['username'] ?? $_SESSION['name'] ?? 'User';
            $this->body = "
                <div class='content-wrapper mx-auto surface-medium p-2 border-radius-8 mt-3'>
                    <div class='text-center mb-2'>
                        <span class='material-symbols-outlined icon-xl text-warning'>lock</span>
                        <h2 class='mt-1'>Access Required</h2>
                        <p>You don't have permission to access this resource.</p>
                        <p class='mt-1'>You can request access from the administrator.</p>
                        <p class='text-muted'>Requesting permission: <strong>" . htmlspecialchars($permissionKey) . "</strong></p>
                    </div>
                    <form id='accessRequestForm' method='POST' action='/access-request/submit'>
                        <input type='hidden' name='requested_url' value='" . htmlspecialchars($requestedUrl) . "'>
                        <input type='hidden' name='permission' value='" . htmlspecialchars($permissionKey) . "'>
                        <div class='form-group'>
                            <label for='reason'>Reason for Access Request:</label>
                            <textarea name='reason' id='reason' rows='6' placeholder='Please explain why you need access to this resource...' required></textarea>
                        </div>
                        <div class='text-center mt-2'>
                            <button type='submit' class='btn-primary'>
                                <span class='material-symbols-outlined icon-before-text'>send</span>
                                Request Access
                            </button>
                        </div>
                    </form>
                </div>
            ";
        }

        $this->build($this->body);
    }

    /**
     * Handle access request form submission
     * POST /access-request/submit
     */
    #[RequireLogin]
    #[AllowedMethods('POST')]
    #[RequiredParams(['requested_url', 'reason', 'permission'])]
    public function submit() {
        // Get form data from POST - ONLY trust session data for identity
        $requestedUrl = $_POST['requested_url'];
        $reason = $_POST['reason'];
        $permission = $_POST['permission'];

        $userId = \Zero\Core\Session::userId();
        $userEmail = $_SESSION['user_email'] ?? $_SESSION['email'] ?? 'Unknown';
        $userName = $_SESSION['user_name'] ?? $_SESSION['username'] ?? $_SESSION['name'] ?? "User #$userId";
        $userIdentifier = "$userName (ID: $userId)";

        // Generate unique token for approval
        $token = bin2hex(random_bytes(32));

        // Save to database
        try {
            $db = \Zero\Core\Database::getConnection();
            $stmt = $db->prepare(
                "INSERT INTO access_requests (user_id, requested_permission, requested_url, reason, token)
                 VALUES (?, ?, ?, ?, ?)"
            );
            $stmt->execute([$userId, $permission, $requestedUrl, $reason, $token]);
            $requestId = $db->lastInsertId();

            Console::info("Access request #$requestId created for user $userId requesting permission '$permission'");
        } catch (\Exception $e) {
            Console::error("Failed to save access request: " . $e->getMessage());
            $this->error("There was a problem saving your request. Please try again later.");
            return;
        }

        // Prepare email content with approval button
        $subject = "Access Request from $userName";
        $approveUrl = "https://" . PRIMARY_DOMAIN . "/access-request/approve?token=" . urlencode($token);

        // Email HTML needs inline styles for email client compatibility
        $message = "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background-color: #4CAF50; color: white; padding: 10px; text-align: center; }
                .content { background-color: #f9f9f9; padding: 20px; border: 1px solid #ddd; }
                .field { margin-bottom: 15px; }
                .label { font-weight: bold; color: #555; }
                .value { margin-top: 5px; padding: 10px; background-color: white; border-left: 3px solid #4CAF50; }
                .approve-btn { display: inline-block; padding: 12px 24px; background-color: #4CAF50; color: white; text-decoration: none; border-radius: 4px; margin-top: 20px; }
                .approve-btn:hover { background-color: #45a049; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h2>New Access Request</h2>
                </div>
                <div class='content'>
                    <div class='field'>
                        <div class='label'>User:</div>
                        <div class='value'>$userIdentifier</div>
                    </div>
                    <div class='field'>
                        <div class='label'>Email:</div>
                        <div class='value'>$userEmail</div>
                    </div>
                    <div class='field'>
                        <div class='label'>Requested Permission:</div>
                        <div class='value'>" . htmlspecialchars($permission) . "</div>
                    </div>
                    <div class='field'>
                        <div class='label'>Requested URL:</div>
                        <div class='value'>$requestedUrl</div>
                    </div>
                    <div class='field'>
                        <div class='label'>Reason:</div>
                        <div class='value'>" . nl2br(htmlspecialchars($reason)) . "</div>
                    </div>
                    <div class='field'>
                        <div class='label'>Timestamp:</div>
                        <div class='value'>" . date('Y-m-d H:i:s') . "</div>
                    </div>
                    <div class='field'>
                        <div class='label'>IP Address:</div>
                        <div class='value'>" . ($_SERVER['REMOTE_ADDR'] ?? 'Unknown') . "</div>
                    </div>
                    <div style='text-align: center; margin-top: 20px;'>
                        <a href='$approveUrl' class='approve-btn'>Approve Request</a>
                        <p style='font-size: 12px; color: #666; margin-top: 10px;'>Note: You must be logged in as an admin to approve this request.</p>
                    </div>
                </div>
            </div>
        </body>
        </html>
        ";

        // Send email to admin
        // Mail::sendEmailTo() sends from the site's own address (MAIL_FROM), marks
        // the message as HTML, records it, and honours MAIL_CAPTURE_DIR.
        $replyTo = filter_var($userEmail, FILTER_VALIDATE_EMAIL) ? $userEmail : null;

        try {
            $emailSent = Mail::sendEmailTo(NOTIFY_EMAIL, $subject, $message, null, $replyTo);

            if ($emailSent) {
                Console::info("Access request email sent to " . NOTIFY_EMAIL);
                $this->success("Your access request has been submitted successfully. The administrator will review your request.");
            } else {
                Console::error("Failed to send access request email");
                $this->error("There was a problem sending your request. Please try again later or contact the administrator directly.");
            }
        } catch (\Exception $e) {
            Console::error("Exception sending access request email: " . $e->getMessage());
            $this->error("There was a problem sending your request. Please try again later.");
        }
    }

    /**
     * Approve an access request
     * GET /access-request/approve?token=xxx
     */
    #[RequireLogin]
    #[RequiredParams(['token'])]
    public function approve() {
        $adminId = \Zero\Core\Session::userId();
        $token = $_GET['token'];

        // Check if admin has permission to approve. $adminId is \Zero\Core\Session::userId()
        // (the acting user), so this is a direct substitution for
        // Session::can() — no need to User::find($adminId) and call
        // can() on a specific instance. Also replaces a fourth hand-rolled
        // copy of the allow.admin check with the one true predicate in
        // User::can(): a lookup failure inside capabilities() is caught there
        // (\Throwable, not \Exception — Database::getConnection() can throw
        // \Error on misconfiguration) and denies, so there is nothing left
        // for a local try/catch to do here.
        $hasAdminPermission = \Zero\Core\Session::can('allow.admin');

        if (!$hasAdminPermission) {
            $this->error("You do not have administrator permissions to approve access requests.");
            return;
        }

        // Get the access request
        try {
            $db = \Zero\Core\Database::getConnection();
            $stmt = $db->prepare(
                "SELECT * FROM access_requests WHERE token = ? AND status = 'pending'"
            );
            $stmt->execute([$token]);
            $request = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$request) {
                $this->error("Invalid or already processed access request.");
                return;
            }

            // Grant the permission to the user. Routed through
            // User::grant() so the write path matches the read in
            // User::can() — $adminId is the approving admin, recorded as
            // granted_by via grant()'s $actorId parameter.
            $targetUser = \Zero\Core\Access\User::find($request['user_id']);
            if (!$targetUser || !$targetUser->grant($request['requested_permission'], $adminId)) {
                $this->error("Failed to grant the requested permission. Please try again later.");
                return;
            }

            // Update the access request status
            $stmt = $db->prepare(
                "UPDATE access_requests SET status = 'approved', approved_at = NOW(), approved_by = ? WHERE id = ?"
            );
            $stmt->execute([$adminId, $request['id']]);

            Console::info("Admin user $adminId approved access request #{$request['id']} - granted permission '{$request['requested_permission']}' to user {$request['user_id']}");

            $this->success(
                "Access request approved successfully! Permission '{$request['requested_permission']}' has been granted to the user.",
                ['request_id' => $request['id']]
            );

        } catch (\Exception $e) {
            Console::error("Error approving access request: " . $e->getMessage());
            $this->error("There was a problem approving the request. Please try again later.");
        }
    }

    /**
     * Show success page
     * Overrides parent success() to provide custom HTML display
     */
    protected function success($message = null, $data = []) {
        if (Request::$acceptsJSON) {
            parent::success($message, $data);
        } else {
            $this->body = "
                <div class='content-wrapper mx-auto text-center surface-medium p-2 border-radius-8 mt-3'>
                    <span class='material-symbols-outlined icon-xl text-success'>check_circle</span>
                    <h2>Request Submitted</h2>
                    <p>$message</p>
                    <a href='/' class='btn-primary mt-2'>Return Home</a>
                </div>
            ";
            $this->build($this->body);
        }
    }

    /**
     * Show error page
     * Overrides parent error() to provide custom HTML display
     */
    protected function error($message, $data = []) {
        if (Request::$acceptsJSON) {
            parent::error($message, $data);
        } else {
            $this->body = "
                <div class='content-wrapper mx-auto text-center surface-medium p-2 border-radius-8 mt-3'>
                    <span class='material-symbols-outlined icon-xl text-error'>error</span>
                    <h2>Error</h2>
                    <p>$message</p>
                    <a href='javascript:history.back()' class='btn-secondary mt-2'>Go Back</a>
                </div>
            ";
            $this->build($this->body);
        }
    }
}
