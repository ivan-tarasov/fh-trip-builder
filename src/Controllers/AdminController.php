<?php

declare(strict_types=1);

namespace TripBuilder\Controllers;

use Exception;
use TripBuilder\Admin;
use TripBuilder\Csrf;
use TripBuilder\Http\HttpStatus;
use TripBuilder\Http\RateLimit;
use TripBuilder\View\TwigRenderer;
use Twig\Error\Error;

/**
 * The way in, and the way out.
 *
 * One password, one session flag, no accounts table -- see `Admin` for why that
 * is the honest size of this rather than a corner cut (A3.2, #100).
 *
 * Everything under `/admin` goes through `guard()`, so a page added here is
 * gated by being here. That is deliberate: a panel where each action remembers
 * to check for itself is a panel where one of them eventually does not.
 */
class AdminController extends AbstractController
{
    /**
     * What a wrong password is told.
     *
     * One message for every way of being wrong, and it names neither the
     * password nor whether one is configured. There is a single account, so
     * "no such user" and "wrong password" would be the same sentence anyway --
     * but a panel that says "no password is set on this server" is telling a
     * stranger something worth knowing.
     */
    private const string REFUSED = 'That is not the password.';

    /**
     * The panel itself.
     *
     * @throws Exception|Error
     */
    public function index(): void
    {
        if (!$this->guard()) {
            return;
        }

        echo new TwigRenderer()->renderPage('admin/index.html.twig', [
            'idle_minutes' => Admin::IDLE_MINUTES,
        ]);
    }

    /**
     * The sign-in form, and the posting of it.
     *
     * @throws Exception|Error
     */
    public function login(): void
    {
        if (Admin::isSignedIn()) {
            $this->bounce('/admin');

            return;
        }

        if (!$this->request->isPost()) {
            $this->form();

            return;
        }

        // The token first, then the throttle. A 429 that arrived first would
        // answer a different question than it looks like -- whether the token
        // was accepted -- and would do it without spending one. The same order
        // AjaxController::guardFailure() settled on.
        if (!Csrf::isValid($this->request->body->nullableStr(Csrf::FIELD))) {
            $this->form(self::REFUSED, HttpStatus::Forbidden);

            return;
        }

        if ($this->isOverLimit(RateLimit::AdminLogin)) {
            $this->form(RateLimit::AdminLogin->refusal(), HttpStatus::TooManyRequests);

            return;
        }

        if (!Admin::verify($this->request->body->str('password'))) {
            $this->form(self::REFUSED, HttpStatus::Unauthorized);

            return;
        }

        Admin::signIn();
        $this->bounce('/admin');
    }

    /**
     * Sign out, on a POST.
     *
     * A link would do it too, and that is the reason it is a form: a GET that
     * changes something can be fired by any image tag on any page, and being
     * signed out by one is a small nuisance that says the panel is not careful.
     *
     * @throws Exception|Error
     */
    public function logout(): void
    {
        if ($this->request->isPost() && Csrf::isValid($this->request->body->nullableStr(Csrf::FIELD))) {
            Admin::signOut();
        }

        $this->bounce('/admin/login');
    }

    /**
     * Let a signed-in operator through, or send them to the form.
     *
     * Returns false having already answered, so a caller is one `if` away from
     * being gated.
     */
    protected function guard(): bool
    {
        if (Admin::isSignedIn()) {
            return true;
        }

        $this->bounce('/admin/login');

        return false;
    }

    /**
     * @throws Exception|Error
     */
    private function form(?string $error = null, HttpStatus $status = HttpStatus::Ok): void
    {
        if ($status !== HttpStatus::Ok && !headers_sent()) {
            http_response_code($status->value);
        }

        echo new TwigRenderer()->renderPage('admin/login.html.twig', [
            'error' => $error,
            // So a server with no hash says so on its own sign-in page, where
            // the person who can fix it is standing, rather than refusing a
            // correct password with no explanation.
            'configured' => Admin::isConfigured(),
        ]);
    }
}
