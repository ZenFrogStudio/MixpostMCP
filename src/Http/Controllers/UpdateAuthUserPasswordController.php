<?php

namespace OneMediaLabs\MixpostMcp\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use OneMediaLabs\MixpostMcp\Http\Requests\UpdateAuthUserPassword;

class UpdateAuthUserPasswordController extends Controller
{
    public function __invoke(UpdateAuthUserPassword $updateAuthUserPassword): RedirectResponse
    {
        $updateAuthUserPassword->handle();

        return back();
    }
}
