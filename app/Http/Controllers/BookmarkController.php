<?php

namespace App\Http\Controllers;

use App\Bookmark;
use App\Status;
use Auth;
use Illuminate\Http\Request;

class BookmarkController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function store(Request $request)
    {
        $this->validate($request, [
            'item' => 'required|integer|min:1',
        ]);

        $profile = Auth::user()->profile;
        $status = Status::findOrFail($request->input('item'));

        $bookmark = Bookmark::firstOrCreate(
            ['status_id' => $status->id], ['profile_id' => $profile->id]
        );

        if (!$bookmark->wasRecentlyCreated) {
            $bookmark->delete();
        }

        if ($request->ajax()) {
            $response = ['code' => 200, 'msg' => 'Bookmark saved!'];
        } else {
            $response = redirect()->back();
        }

        return $response;
    }

    public function sendVerifyEmail(Request $request)
    {
        $recentAttempt = EmailVerification::whereUserId(Auth::id())
        ->whereDate('created_at', '>', now()->subHours(12))->count();

        if ($recentAttempt > 0) {
            return redirect()->back()->with('error', 'A verification email has already been sent recently. Please check your email, or try again later.');
        } 

        EmailVerification::whereUserId(Auth::id())->delete();

        $user = User::whereNull('email_verified_at')->find(Auth::id());
        $utoken = str_random(64);
        $rtoken = str_random(128);

        $verify = new EmailVerification();
        $verify->user_id = $user->id;
        $verify->email = $user->email;
        $verify->user_token = $utoken;
        $verify->random_token = $rtoken;
        $verify->save();

        Mail::to($user->email)->send(new ConfirmEmail($verify));

        return redirect()->back()->with('status', 'Verification email sent!');
    }
}
