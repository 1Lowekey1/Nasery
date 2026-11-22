<?php

namespace Tests\Feature\Media;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class ImageUploadTest extends TestCase
{
    use InteractsWithOrganizations;

    protected function tearDown(): void
    {
        File::deleteDirectory(base_path('storage/framework/testing/media'));
        parent::tearDown();
    }

    /**
     * A real JPEG with an APP1 "EXIF" block (fake GPS) and extra data appended (a polyglot).
     * Harmless markers, not real attack code: real web-shell text trips antivirus on the test's temp files.
     */
    private function nastyJpeg(int $w = 900, int $h = 600): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 40, 95, 107));
        ob_start();
        imagejpeg($img, null, 90);
        $jpeg = (string) ob_get_clean();
        $exif = "Exif\0\0GPSLatitude=7.0731;GPSLongitude=125.6128;SECRET-LOCATION-MARKER";
        $app1 = "\xFF\xE1".pack('n', strlen($exif) + 2).$exif;

        return substr($jpeg, 0, 2).$app1.substr($jpeg, 2).'APPENDED-PAYLOAD-MARKER';
    }

    private function png(int $w, int $h): string
    {
        $img = imagecreatetruecolor($w, $h);
        ob_start();
        imagepng($img);

        return (string) ob_get_clean();
    }

    private function upload(User $user, string $url, string $name, string $content, string $method = 'POST'): TestResponse
    {
        return $this->actingAs($user)->call($method, $url, [], [], ['image' => UploadedFile::fake()->createWithContent($name, $content)],
            $this->transformHeadersToServerVars([...self::SPA, 'Accept' => 'application/json']));
    }

    private function stored(?string $url): string
    {
        $this->assertNotNull($url);
        $relative = Str::after($url, '/api/v1/media/');

        return base_path('storage/framework/testing/media/'.$relative);
    }

    public function test_profile_photo_is_re_encoded_without_metadata_or_payload(): void
    {
        $user = User::factory()->create();

        $url = $this->upload($user, '/api/v1/auth/me/avatar', 'me.jpg', $this->nastyJpeg())
            ->assertOk()->json('data.avatar_url');
        $file = $this->stored($url);
        $this->assertFileExists($file);

        // A new 512×512 WebP: none of the original's metadata or the appended data survived.
        [$w, $h, $type] = getimagesize($file);
        $this->assertSame([512, 512, IMAGETYPE_WEBP], [$w, $h, $type]);
        $bytes = (string) file_get_contents($file);
        $this->assertStringNotContainsString('SECRET-LOCATION-MARKER', $bytes);
        $this->assertStringNotContainsString('GPSLatitude', $bytes);
        $this->assertStringNotContainsString('APPENDED-PAYLOAD-MARKER', $bytes);

        // Shown on /auth/me and served as an image (never as a script or page).
        $this->actingAs($user)->spaJson('GET', '/api/v1/auth/me')->assertJsonPath('data.avatar_url', $url);
        $this->get(Str::after($url, url('')))->assertOk()
            ->assertHeader('Content-Type', 'image/webp')->assertHeader('X-Content-Type-Options', 'nosniff');

        // Replacing deletes the old file; removing clears it.
        $second = $this->upload($user, '/api/v1/auth/me/avatar', 'again.png', $this->png(300, 300))->assertOk()->json('data.avatar_url');
        $this->assertFileDoesNotExist($file);
        $this->actingAs($user)->spaJson('DELETE', '/api/v1/auth/me/avatar')->assertOk()->assertJsonPath('data.avatar_url', null);
        $this->assertFileDoesNotExist($this->stored($second));
    }

    public function test_only_real_jpg_png_webp_within_limits_are_accepted(): void
    {
        $user = User::factory()->create();
        $reject = fn (string $name, string $content) => $this->upload($user, '/api/v1/auth/me/avatar', $name, $content)
            ->assertUnprocessable()->json('error.details.fields.image.0');

        // Disguised files: the name says image, the content doesn't (harmless text; the check is by content).
        $this->assertSame('Choose a JPG, PNG or WebP image.', $reject('shell.jpg', 'NOT-AN-IMAGE: plain text named like a photo'));
        $this->assertSame('Choose a JPG, PNG or WebP image.', $reject('page.png', '<html><body>NOT-AN-IMAGE</body></html>'));
        // Formats that aren't accepted at all (SVG is refused whatever it contains).
        $this->assertSame('Choose a JPG, PNG or WebP image.', $reject('vector.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect width="10" height="10"/></svg>'));
        $gif = imagecreatetruecolor(200, 200);
        ob_start();
        imagegif($gif);
        $this->assertSame('Choose a JPG, PNG or WebP image.', $reject('anim.gif', (string) ob_get_clean()));
        // Too big in pixels (decompression bomb guard) and too small.
        $this->assertStringContainsString('too large', $reject('huge.png', $this->png(6500, 120)));
        $this->assertStringContainsString('too small', $reject('tiny.png', $this->png(40, 40)));
        // Too big in bytes.
        $this->upload($user, '/api/v1/auth/me/avatar', 'big.jpg', str_repeat('x', 6 * 1024 * 1024))->assertUnprocessable();

        $this->assertNull(DB::table('users')->where('id', $user->id)->value('avatar_path'));
        $this->assertSame([], File::glob(base_path('storage/framework/testing/media/avatars/*')));
    }

    public function test_organizer_logo_and_cover_need_permission_and_show_publicly(): void
    {
        [$org, $owner] = $this->listedOrganizer('Pictures Co');
        $slug = $org->slug;
        $base = "/api/v1/organizations/{$org->id}/profile";

        $logo = $this->upload($owner, "{$base}/logo", 'logo.png', $this->png(400, 200))->assertOk()->json('data.url');
        $cover = $this->upload($owner, "{$base}/cover", 'cover.jpg', $this->nastyJpeg(2400, 1200))->assertOk()->json('data.url');
        [$w, $h] = getimagesize($this->stored($logo));
        $this->assertSame([512, 512], [$w, $h]);
        [$w, $h] = getimagesize($this->stored($cover));
        $this->assertSame([1600, 600], [$w, $h]);

        // Public organizer page and directory show them.
        $this->getJson("/api/v1/marketplace/organizers/{$slug}")->assertJsonPath('data.logo_url', $logo)->assertJsonPath('data.cover_url', $cover);
        $this->assertSame($logo, collect($this->getJson('/api/v1/marketplace/organizers')->json('data'))->firstWhere('slug', $slug)['logo_url'] ?? null);

        // Not theirs to change: a client, an outsider organizer, staff (owner only, like editing the
        // profile). Refused as 403/404: an organization isn't confirmed to non-owners. Nothing changes.
        [, $otherOwner] = $this->listedOrganizer('Other Co');
        $staff = $this->businessUser();
        $this->addStaff($org, $staff);
        foreach ([User::factory()->create(), $otherOwner, $staff] as $intruder) {
            $this->assertContains($this->upload($intruder, "{$base}/cover", 'x.png', $this->png(200, 200))->status(), [403, 404]);
        }
        $this->getJson("/api/v1/marketplace/organizers/{$slug}")->assertJsonPath('data.cover_url', $cover);

        $this->actingAs($owner)->spaJson('DELETE', "{$base}/logo")->assertOk();
        $this->assertFileDoesNotExist($this->stored($logo));
        $this->getJson("/api/v1/marketplace/organizers/{$slug}")->assertJsonPath('data.logo_url', null);
    }

    public function test_event_cover_needs_event_update_and_shows_on_public_events(): void
    {
        [$org, $owner] = $this->listedOrganizer('Cover Events Co');
        $event = $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/events", [
            'name' => 'Rooftop Night', 'visibility' => 'public',
            'sessions' => [['starts_at' => now('Asia/Manila')->addDays(10)->format('Y-m-d').'T18:00', 'ends_at' => now('Asia/Manila')->addDays(10)->format('Y-m-d').'T23:00']],
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('data.id');
        $url = "/api/v1/organizations/{$org->id}/events/{$event}/cover";

        $staff = $this->businessUser();
        $this->addStaff($org, $staff);
        $this->upload($staff, $url, 'x.jpg', $this->nastyJpeg())->assertForbidden();

        $cover = $this->upload($owner, $url, 'cover.jpg', $this->nastyJpeg(1920, 1080))->assertOk()->json('data.url');
        [$w, $h] = getimagesize($this->stored($cover));
        $this->assertSame([1600, 900], [$w, $h]);
        $this->actingAs($owner)->spaJson('GET', "/api/v1/organizations/{$org->id}/events/{$event}")->assertJsonPath('data.cover_url', $cover);

        // Once public, the marketplace event page shows it.
        DB::table('ticket_types')->insert(['id' => (string) Str::uuid7(), 'event_id' => $event, 'name' => 'Entry', 'price' => 0, 'capacity' => 50, 'status' => 'on_sale', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/events/{$event}/publish")->assertOk();
        $this->getJson("/api/v1/public-events/{$event}")->assertJsonPath('data.cover_url', $cover);
    }

    public function test_media_urls_only_serve_stored_pictures(): void
    {
        foreach (['/api/v1/media/avatars/../../.env', '/api/v1/media/secrets/'.Str::uuid().'.webp',
            '/api/v1/media/avatars/'.Str::uuid().'.php', '/api/v1/media/avatars/'.Str::uuid().'.webp'] as $path) {
            $this->get($path)->assertNotFound();
        }
    }

    /** 0062 (organizer field test): price list items get a picture and an "includes" list. */
    public function test_price_list_items_have_pictures_and_inclusions(): void
    {
        [$org, $owner] = $this->listedOrganizer();
        [, $other] = $this->listedOrganizer();
        $base = "/api/v1/organizations/{$org->id}/price-options";
        $id = $this->actingAs($owner)->spaJson('POST', $base, ['name' => 'Bridal package', 'pricing_unit' => 'flat', 'price' => 50000,
            'includes' => ['Bridal car', ' 2 photographers ', '', 'Bridal car']])->assertCreated()
            ->assertJsonPath('data.includes', ['Bridal car', '2 photographers'])->assertJsonPath('data.image_url', null)->json('data.id');

        $this->upload($other, "{$base}/{$id}/images", 'car.png', $this->png(800, 600))->assertNotFound();
        $url = $this->upload($owner, "{$base}/{$id}/images", 'car.png', $this->png(800, 600))->assertOk()->json('data.image_url');
        $this->assertFileExists($this->stored($url));
        $this->get(Str::after($url, url('/')))->assertOk()->assertHeader('Content-Type', 'image/webp');

        // 0063: a gallery of up to 10; the first is the cover, and any can be made the cover.
        $images = $this->upload($owner, "{$base}/{$id}/images", 'car2.png', $this->png(640, 480))->assertOk()
            ->assertJsonPath('data.image_url', $url)->assertJsonCount(2, 'data.images')->json('data.images');
        $this->getJson("/api/v1/marketplace/organizers/{$org->slug}")->assertOk()
            ->assertJsonPath('data.price_options.0.image_url', $url)->assertJsonPath('data.price_options.0.images.1.url', $images[1]['url'])
            ->assertJsonPath('data.price_options.0.includes.1', '2 photographers');
        $this->actingAs($owner)->spaJson('POST', "{$base}/{$id}/images/{$images[1]['id']}/cover")->assertOk()
            ->assertJsonPath('data.image_url', $images[1]['url'])->assertJsonPath('data.images.1.id', $images[0]['id']);
        for ($i = 2; $i < 10; $i++) {
            $this->upload($owner, "{$base}/{$id}/images", "p{$i}.png", $this->png(300, 200))->assertOk();
        }
        $this->upload($owner, "{$base}/{$id}/images", 'p10.png', $this->png(300, 200))->assertUnprocessable();

        // Removing deletes the file and keeps the rest.
        $this->actingAs($owner)->spaJson('DELETE', "{$base}/{$id}/images/{$images[0]['id']}")->assertOk()
            ->assertJsonCount(9, 'data.images')->assertJsonPath('data.image_url', $images[1]['url']);
        $this->assertFileDoesNotExist($this->stored($url));
    }

    /** 0062: the client's mood board, seen by organizers who get the request and the event team. */
    public function test_clients_add_mood_board_pictures_that_invited_organizers_see(): void
    {
        [$org, $owner] = $this->listedOrganizer();
        $client = User::factory()->create();
        $stranger = User::factory()->create();
        $id = $this->actingAs($client)->spaJson('POST', '/api/v1/inquiries', [
            'event_type_id' => DB::table('event_types')->whereNull('organization_id')->where('name', 'Wedding')->value('id'),
            'requested_date' => now()->addMonths(3)->toDateString(), 'guest_count' => 80, 'location_text' => 'Lanang, Davao City',
            'services' => [['custom_service_name' => 'Wedding coordination']],
        ])->assertCreated()->json('data.id');
        $board = "/api/v1/inquiries/{$id}/mood-board";

        $this->upload($stranger, $board, 'palette.png', $this->png(600, 400))->assertNotFound();
        $first = $this->actingAs($client)->call('POST', $board, ['caption' => 'Sage and cream'], [], ['image' => UploadedFile::fake()->createWithContent('palette.png', $this->png(600, 400))],
            $this->transformHeadersToServerVars([...self::SPA, 'Accept' => 'application/json']))
            ->assertCreated()->assertJsonPath('data.mood_board.0.caption', 'Sage and cream')->json('data.mood_board.0');
        $this->get(Str::after($first['url'], url('/')))->assertOk()->assertHeader('Content-Type', 'image/webp');
        $this->upload($client, $board, 'tables.png', $this->png(600, 400))->assertCreated()->assertJsonCount(2, 'data.mood_board');

        $this->actingAs($client)->spaJson('POST', "/api/v1/inquiries/{$id}/submit", ['mode' => 'direct', 'organization_ids' => [$org->id]],
            ['Idempotency-Key' => (string) Str::uuid()])->assertOk();
        $this->actingAs($owner)->spaJson('GET', "/api/v1/organizations/{$org->id}/inquiries/{$id}")->assertOk()
            ->assertJsonCount(2, 'data.mood_board')->assertJsonPath('data.mood_board.0.url', $first['url']);

        $this->actingAs($client)->spaJson('DELETE', "{$board}/{$first['id']}")->assertOk()->assertJsonCount(1, 'data.mood_board');
        $this->assertFileDoesNotExist($this->stored($first['url']));

        // At most 8 pictures.
        DB::table('inquiries')->where('id', $id)->update(['mood_board' => json_encode(array_fill(0, 8, ['path' => 'mood-boards/'.Str::uuid().'.webp', 'caption' => null]))]);
        $this->upload($client, $board, 'one-more.png', $this->png(600, 400))->assertUnprocessable();
    }

    /** Field test (Nov 2026): once booked, the organizer adds to the mood board and removes only its own pictures. */
    public function test_the_booked_organizer_adds_to_the_mood_board_and_removes_only_its_own(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $key = fn () => ['Idempotency-Key' => (string) Str::uuid()];
        [$org, $owner] = $this->listedOrganizer();
        [$rival, $rivalOwner] = $this->listedOrganizer();
        $client = User::factory()->create();
        $id = $this->actingAs($client)->spaJson('POST', '/api/v1/inquiries', [
            'event_type_id' => DB::table('event_types')->whereNull('organization_id')->where('name', 'Wedding')->value('id'),
            'requested_date' => now()->addMonths(2)->toDateString(), 'guest_count' => 90, 'location_text' => 'Lanang, Davao City',
        ])->json('data.id');
        $clientPicture = $this->upload($client, "/api/v1/inquiries/{$id}/mood-board", 'palette.png', $this->png(600, 400))->assertCreated()->json('data.mood_board.0');
        $this->actingAs($client)->spaJson('POST', "/api/v1/inquiries/{$id}/submit", ['mode' => 'direct', 'organization_ids' => [$org->id, $rival->id]], $key())->assertOk();
        $orgBoard = "/api/v1/organizations/{$org->id}/inquiries/{$id}/mood-board";

        // Not booked yet: no changes.
        $this->actingAs($owner)->spaJson('GET', "/api/v1/organizations/{$org->id}/inquiries/{$id}")->assertJsonPath('data.mood_board_editable', false);
        $this->upload($owner, $orgBoard, 'final.png', $this->png(600, 400))->assertStatus(409);

        $quotation = $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/inquiries/{$id}/quotations",
            ['items' => [['description' => 'Package', 'quantity' => 1, 'unit_price' => 50000]], 'submit' => true], $key())->json('data.id');
        $this->actingAs($client)->spaJson('POST', "/api/v1/quotations/{$quotation}/accept", [], $key())->assertOk();
        $start = now('Asia/Manila')->addDays(30);
        $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/quotations/{$quotation}/event", [
            'name' => 'Palette Wedding', 'sessions' => [['starts_at' => $start->format('Y-m-d\TH:i'), 'ends_at' => $start->addHours(4)->format('Y-m-d\TH:i')]],
        ], $key())->assertCreated();

        // Booked: the team adds a picture, labelled as theirs; the client sees it.
        $this->actingAs($owner)->spaJson('GET', "/api/v1/organizations/{$org->id}/inquiries/{$id}")->assertJsonPath('data.mood_board_editable', true);
        $ours = $this->upload($owner, $orgBoard, 'final.png', $this->png(600, 400))->assertCreated()
            ->assertJsonCount(2, 'data.mood_board')->assertJsonPath('data.mood_board.1.added_by', $org->display_name)->json('data.mood_board.1');
        $this->actingAs($client)->spaJson('GET', "/api/v1/inquiries/{$id}")->assertJsonPath('data.mood_board.1.added_by', $org->display_name)
            ->assertJsonPath('data.mood_board.0.added_by', null);

        // The losing organizer can't; the booked one can't remove the client's picture, only its own.
        $this->upload($rivalOwner, "/api/v1/organizations/{$rival->id}/inquiries/{$id}/mood-board", 'x.png', $this->png(300, 200))->assertStatus(409);
        $this->actingAs($owner)->spaJson('DELETE', "{$orgBoard}/{$clientPicture['id']}")->assertForbidden();
        $this->actingAs($owner)->spaJson('DELETE', "{$orgBoard}/{$ours['id']}")->assertOk()->assertJsonCount(1, 'data.mood_board');
        $this->assertFileDoesNotExist($this->stored($ours['url']));
    }
}
