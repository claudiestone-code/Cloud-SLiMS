<?php
/**
 *
 * Forgot Password for Administrator
 * Copyright (C) 2020 Eddy Subratha (eddy.subratha@gmail.com)
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301  USA
 *
 */

use SLiMS\Url;
use SLiMS\Http\Client;
use SLiMS\Auth\LoginThrottle;
use SLiMS\Captcha\Factory as Captcha;

// be sure that this file not accessed directly
if (!defined('INDEX_AUTH')) {
    die("can not access this file directly");
} elseif (INDEX_AUTH != 1) { 
    die("can not access this file directly");
}

/*
if (defined('LIGHTWEIGHT_MODE')) {
    header('Location: index.php');
}
*/

// required file
require SIMBIO.'simbio_DB/simbio_dbop.inc.php';

// https connection (if enabled)
if ($sysconf['https_enable']) {
    simbio_security::doCheckHttps($sysconf['https_port']);
}

// Captcha initialize
$captcha = Captcha::section('forgot');

// start the output buffering for main content
ob_start();

if (isset($_POST['resetPass'])) {
    $email = $dbs->escape_string($_POST['currentmail']);
    if (!$email) {
        echo '<script type="text/javascript">alert(\''.__('Please supply valid username and password').'\');</script>';
    } else {
        # <!-- Captcha form processing - start -->
        if ($captcha->isSectionActive()) {    
            try {
                if ($captcha->isValid() === false) throw new Exception(__('Captcha incorrect.'));

                // rate limit reset requests (per e-mail and per IP): stops mail
                // flooding and slows down probing for registered addresses
                if ($wait = LoginThrottle::retryAfter('forgot', $email)) throw new Exception(LoginThrottle::message($wait));
                LoginThrottle::fail('forgot', $email);

                // the answer is the same whether or not the address exists, so
                // the form cannot be used to discover staff e-mail addresses
                $genericSuccess = __('<strong>Thank you.</strong> If this e-mail address belongs to a staff account, password reset instructions have been sent to it.');

                // Validate current email
                $_q = $dbs->query("SELECT user_id, realname FROM user WHERE email='{$email}'");

                if ($_q->num_rows === 0) {
                    flash('resetSuccess', $genericSuccess);
                } else {
                    // Name
                    $file_d = $_q->fetch_assoc();
                    $name = $file_d['realname'];
                    /// Generate a token for forgot password
                    // "<issued unix time>.<64 random hex chars>" (75 chars, fits `forgot` VARCHAR(80)).
                    // The timestamp lets newpass.inc.php expire the token without a schema change.
                    $salt = time() . '.' . bin2hex(random_bytes(32));
                    $_sql_update_salt = sprintf("UPDATE user SET forgot = '{$salt}', last_update = CURDATE() WHERE email = '%s'", $email);
                    // write log
                    writeLog('staff', $name, 'Forgot Password', $name.' has been requested a new password.', 'Password', 'Request');
                    $_update_q = $dbs->query($_sql_update_salt);

                    // reset link on this server
                    $reset_link = (Url::isHttps() ? 'https://' : 'http://') . Url::getDomain() . SWB
                        . 'index.php?p=newpass&email=' . urlencode(stripslashes($email)) . '&salt=' . urlencode($salt);

                    // SECURITY: send the reset link with this library's own SMTP
                    // server (System > Mail Setting) when it is configured, so the
                    // reset token never leaves your infrastructure. The SLiMS
                    // project's public mailer is only a fallback, and can be turned
                    // off with config/password_reset.php: ['remote_mailer' => false]
                    $mailConfig = config('mail');
                    $localMailReady = is_array($mailConfig) && !empty($mailConfig['server']) && $mailConfig['server'] !== '_server_';

                    if ($localMailReady) {
                        try {
                            \SLiMS\Mail::to(stripslashes($email), $name)
                                ->subject(__('Password reset request') . ' - ' . $sysconf['library_name'])
                                ->message(
                                    sprintf(__('Hello %s,'), $name) . "\n\n"
                                    . __('A password reset was requested for your SLiMS staff account. Open this link within 1 hour to choose a new password:') . "\n\n"
                                    . $reset_link . "\n\n"
                                    . __('If you did not request this, ignore this e-mail; your password stays unchanged.')
                                )
                                ->send();
                        } catch (\Throwable $mailError) {
                            throw new Exception(__('Cannot send the email. Please try again.') . (isDev() ? ' ' . $mailError->getMessage() : ''));
                        }
                    } else if (config('password_reset.remote_mailer', true) !== false) {
                        // set hook process variable
                        $hookProcess = Client::withHeaders([
                            "X-API-KEY" => $salt
                        ])->post('https://slims.web.id/mailer/forgot.php', [
                            'url' => (string)Url::getSlimsFullUri(),
                            'salt' => $salt,
                            'email' => $email,
                            'name' => $name
                        ]);

                        if ($hookProcess->getStatusCode() !== 200 || $hookProcess->getContent() == 'false') {
                            $error = (isDev() ? ' ' . __('Error') . ' : ' . $hookProcess->getError() : ' ' . __('Error not available'));
                            throw new Exception(__('Cannot send the email. Please try again.') . $error);
                        }
                    } else {
                        throw new Exception(__('E-mail is not configured. Please ask the administrator to set up System > Mail Setting.'));
                    }

                    flash('resetSuccess', $genericSuccess);
                }
            } catch (Exception $e) {
                flash('resetFailed', $e->getMessage());
            }
        }
    }
}
?>
<div id="loginForm">
    <noscript>
        <div style="font-weight: bold; color: #FF0000;"><?php echo __('Your browser does not support Javascript or Javascript is disabled. Application won\'t run without Javascript!'); ?><div>
    </noscript>
    <div class="mb-3">
        <?php 
        if (flash()->isEmpty()) {
            // if there is login action
            echo __('If you need help resetting your password, we can help by sending you a link to reset it.');
        } else if ($key = flash()->includes('resetFailed','resetSuccess')) {
            flash()->show($key);
        }
        ?>
    </div>
    <form action="index.php?p=forgot" method="post" novalidation>
        <div class="heading1"><?php echo __('Your email address'); ?></div>
        <div class="login_input"><input type="email" name="currentmail" id="currentmail" class="login_input" required /></div>
        <?php 
        if ($captcha->isSectionActive()) { ?>
            <div class="captchaAdmin">
                <?= $captcha->getCaptcha() ?>
            </div>
            <?php
        }
        ?>
        <div class="marginTop">
        <input type="submit" name="resetPass" value="<?php echo __('Reset my password'); ?>" class="loginButton" />
        <a class="forgotButton" href="index.php?p=login"><?php echo __('Cancel') ?></a>
        </div>
    </form>
</div>
<script type="text/javascript">jQuery('#currentmail').focus();</script>

<?php
// main content
$main_content = ob_get_clean();

// page title
$page_title = __('Forgot My Password').' | '.$sysconf['library_name'];

if ($sysconf['template']['base'] == 'html') {
    // create the template object
    $template = new simbio_template_parser($sysconf['template']['dir'].'/'.$sysconf['template']['theme'].'/login_template.html');
    // assign content to markers
    $template->assign('<!--PAGE_TITLE-->', $page_title);
    $template->assign('<!--CSS-->', $sysconf['template']['css']);
    $template->assign('<!--MAIN_CONTENT-->', $main_content);
    // print out the template
    $template->printOut();
} else if ($sysconf['template']['base'] == 'php') {
    require_once $sysconf['template']['dir'].'/'.$sysconf['template']['theme'].'/login_template.inc.php';
}
exit();
