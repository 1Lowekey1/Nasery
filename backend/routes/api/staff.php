<?php

use App\Http\Controllers\Api\V1\Staff\EventTeamController;
use App\Http\Controllers\Api\V1\Staff\InvitationController;
use App\Http\Controllers\Api\V1\Staff\TeamController;
use Illuminate\Support\Facades\Route;

/*
| B8 Staff access. Business accounts only. {member}/{role} bind under RLS
| (the member's own organizations) and are checked against {organization}.
*/

$uuid = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

Route::middleware(['auth:sanctum', 'active', 'verified', 'account:business'])
    ->where(['organization' => $uuid, 'member' => $uuid, 'role' => $uuid, 'event' => $uuid, 'task' => $uuid, 'eventMember' => $uuid])
    ->group(function () {

        // The signed-in user's own invitations (before they belong to a team)
        Route::get('me/invitations', [InvitationController::class, 'index'])->name('me.invitations');
        Route::post('me/invitations/{member}/accept', [InvitationController::class, 'accept'])->middleware('throttle:sensitive')->name('me.invitations.accept');
        Route::post('me/invitations/{member}/decline', [InvitationController::class, 'decline'])->middleware('throttle:sensitive')->name('me.invitations.decline');
        Route::post('me/team/leave', [InvitationController::class, 'leave'])->middleware('throttle:sensitive')->name('me.team.leave');
        Route::get('me/former-team', [InvitationController::class, 'formerTeam'])->name('me.former-team');

        Route::prefix('organizations/{organization}')->name('organizations.')->group(function () {
            Route::get('team', [TeamController::class, 'index'])->name('team.index');
            Route::post('team/invitations', [TeamController::class, 'invite'])->middleware('throttle:sensitive')->name('team.invite');
            Route::patch('team/{member}', [TeamController::class, 'update'])->middleware('throttle:sensitive')->name('team.update');
            Route::delete('team/{member}', [TeamController::class, 'destroy'])->middleware('throttle:sensitive')->name('team.destroy');
            Route::post('team/{member}/reactivate', [TeamController::class, 'reactivate'])->middleware('throttle:sensitive')->name('team.reactivate');
            Route::get('team/{member}/open-tasks', [TeamController::class, 'openTasks'])->name('team.open-tasks');
            Route::post('team/{member}/resend', [TeamController::class, 'resend'])->middleware('throttle:sensitive')->name('team.resend');

            Route::post('roles', [TeamController::class, 'storeRole'])->middleware('throttle:sensitive')->name('roles.store');
            Route::patch('roles/{role}', [TeamController::class, 'updateRole'])->middleware('throttle:sensitive')->name('roles.update');
            Route::delete('roles/{role}', [TeamController::class, 'destroyRole'])->middleware('throttle:sensitive')->name('roles.destroy');

            Route::get('my-tasks', [EventTeamController::class, 'myTasks'])->name('my-tasks');
            Route::get('my-program', [EventTeamController::class, 'myProgram'])->name('my-program');

            Route::prefix('events/{event}')->name('events.')->scopeBindings()->group(function () {
                Route::get('team', [EventTeamController::class, 'index'])->name('team.index');
                Route::post('team', [EventTeamController::class, 'store'])->middleware('throttle:sensitive')->name('team.store');
                Route::delete('team/{eventMember}', [EventTeamController::class, 'destroy'])->middleware('throttle:sensitive')->name('team.destroy');
                Route::put('tasks/{task}/assignees', [EventTeamController::class, 'assignTask'])->middleware('throttle:sensitive')->name('tasks.assignees');
            });
        });
    });
