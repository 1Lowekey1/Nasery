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
}
