<?php

namespace App\Core;

/**
 * Base controller: view rendering with shared context, guards and JSON helpers.
 */
abstract class Controller
{
    protected array $viewData = [];

    public function __construct()
    {
        $this->viewData['app'] = App::i();
        $this->viewData['settings'] = App::i()->settings();
    }

    protected function view(string $template, array $data = [], ?string $layout = 'layouts/app'): void
    {
        $data = array_merge($this->viewData, [
            'flash'     => Session::takeFlash(),
            'csrf'      => Csrf::token(),
            'authUser'  => Auth::user(),
            'authAdmin' => Auth::admin(),
            'locale'    => Lang::locale(),
        ], $data);

        Response::securityHeaders();
        View::display($template, $data, $layout);
    }

    protected function json(mixed $data = null, string $message = '', int $status = 200, string $code = 'OK'): never
    {
        Response::json($data, $message, $status, $code);
    }

    protected function redirect(string $path): never
    {
        Response::redirect(str_starts_with($path, 'http') ? $path : App::i()->url($path));
    }

    protected function requireUser(): array
    {
        return Auth::requireUser();
    }

    protected function requireAdmin(): array
    {
        return Auth::requireAdmin();
    }

    protected function verifyCsrf(): void
    {
        Csrf::verifyOrFail();
    }

    protected function validate(array $rules, array $labels = []): array
    {
        $data = array_merge($_GET, $_POST, Request::json() ?? []);
        $validator = Validator::make($data, $rules, $labels);

        if ($validator->fails()) {
            if (Request::wantsJson()) {
                Response::json(['errors' => $validator->errors()], $validator->firstError(), 422, 'VALIDATION_FAILED');
            }

            Session::flashInput($data);
            Session::flash('error', $validator->firstError());
            Response::back();
        }

        return $validator->validated();
    }

    /**
     * Paginate helper shared by list screens.
     *
     * @return array{page: int, perPage: int, offset: int}
     */
    protected function pagination(int $defaultPerPage = 25, int $maxPerPage = 100): array
    {
        $page = max(1, Request::int('page', 1));
        $perPage = min($maxPerPage, max(5, Request::int('per_page', $defaultPerPage)));

        return ['page' => $page, 'perPage' => $perPage, 'offset' => ($page - 1) * $perPage];
    }
}
