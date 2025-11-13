<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * File Upload Order Test Suite
 * 
 * IMPORTANT LIMITATION:
 * 
 * These tests validate database storage order but do NOT reproduce the actual
 * race condition bug described in issue #15308. The race condition occurs at
 * the FilePond/Livewire component level during parallel uploads, which cannot
 * be easily tested in PHPUnit without:
 * 
 * 1. Full browser automation (Laravel Dusk/Selenium)
 * 2. Mocking FilePond's parallel upload behavior
 * 3. Simulating network timing variations
 * 4. Testing Livewire component state updates
 * 
 * The actual bug manifests when:
 * - FilePond uploads 2+ files in parallel (maxParallelUploads: 2)
 * - Files complete at different times based on size/network
 * - Completion callbacks fire in completion order, not selection order
 * - Filament/Livewire stores files in the order they complete
 * 
 * Manual testing with real FilePond uploads is required to verify the bug.
 * These tests serve as unit tests for the data model layer only.
 */
class FileUploadOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create a test user
        User::factory()->create([
            'email' => 'test@example.com',
            'name' => 'Test User',
        ]);
    }

    /**
     * Test that files maintain their selection order when stored in database.
     * 
     * NOTE: This test validates database storage order, but does NOT test the actual
     * race condition issue #15308. The race condition occurs at the FilePond/Livewire
     * level during parallel uploads, not at the database storage level.
     * 
     * The actual bug happens when FilePond uploads files in parallel (maxParallelUploads: 2)
     * and files complete at different times, causing them to be stored in completion order
     * rather than selection order. This test simulates correct behavior but doesn't
     * reproduce the actual race condition.
     * 
     * To test the actual race condition, manual testing with real FilePond uploads
     * is required, as it involves:
     * - Client-side FilePond behavior
     * - Livewire component state updates
     * - Network timing variations
     * - Parallel request handling
     */
    public function test_files_maintain_selection_order(): void
    {
        $user = User::where('email', 'test@example.com')->first();
        
        // Create test files with different sizes to simulate race condition
        // Smaller files would upload faster, larger files slower
        Storage::fake('public');
        
        $files = [
            UploadedFile::fake()->image('1.jpg')->size(393), // Large - uploads slower
            UploadedFile::fake()->image('2.jpg')->size(3),   // Small - uploads faster
            UploadedFile::fake()->image('3.jpg')->size(130), // Medium
            UploadedFile::fake()->image('4.jpg')->size(419), // Largest - uploads slowest
            UploadedFile::fake()->image('5.jpg')->size(6),   // Small - uploads fast
        ];
        
        // Simulate the selection order (as user would select them)
        $selectionOrder = ['1.jpg', '2.jpg', '3.jpg', '4.jpg', '5.jpg'];
        
        // Store files in selection order (expected behavior)
        // Use storeAs to preserve original filenames for testing
        $storedPaths = [];
        foreach ($files as $index => $file) {
            $filename = $selectionOrder[$index];
            $path = $file->storeAs('images', $filename, 'public');
            $storedPaths[] = $path;
        }
        
        // Update user with images in selection order
        $user->update(['images' => $storedPaths]);
        
        // Retrieve and verify order
        $user->refresh();
        $retrievedImages = $user->images;
        
        // Assert that files are stored in selection order
        $this->assertCount(5, $retrievedImages);
        
        // Verify the order matches selection order
        foreach ($selectionOrder as $index => $expectedFilename) {
            $storedPath = $retrievedImages[$index];
            $this->assertStringContainsString(
                $expectedFilename,
                $storedPath,
                "File at position {$index} should be {$expectedFilename} but was " . basename($storedPath)
            );
        }
    }

    /**
     * Test that detects when files are stored in completion order (race condition pattern).
     * 
     * NOTE: This test simulates the race condition pattern but does NOT reproduce
     * the actual race condition. It manually stores files in completion order to
     * verify that the test can detect incorrect ordering.
     * 
     * The actual race condition occurs during FilePond parallel uploads when:
     * - Multiple files upload simultaneously (maxParallelUploads: 2)
     * - Smaller files complete faster than larger files
     * - FilePond processes completion callbacks in completion order
     * - Filament/Livewire stores files in the order they complete
     * 
     * This test is useful for ensuring the test framework can detect ordering issues,
     * but manual testing is required to verify the actual bug.
     */
    public function test_detects_race_condition_ordering(): void
    {
        $user = User::where('email', 'test@example.com')->first();
        
        Storage::fake('public');
        
        // Simulate race condition: files stored in completion order
        // (smaller files complete first, larger files complete later)
        $completionOrder = [
            UploadedFile::fake()->image('2.jpg')->size(3),   // Smallest - completes first
            UploadedFile::fake()->image('5.jpg')->size(6),   // Small - completes second
            UploadedFile::fake()->image('3.jpg')->size(130), // Medium - completes third
            UploadedFile::fake()->image('1.jpg')->size(393), // Large - completes fourth
            UploadedFile::fake()->image('4.jpg')->size(419), // Largest - completes last
        ];
        
        $selectionOrder = ['1.jpg', '2.jpg', '3.jpg', '4.jpg', '5.jpg'];
        
        // Store files in completion order (race condition scenario)
        // Simulate completion order: 2, 5, 3, 1, 4
        $completionOrderFilenames = ['2.jpg', '5.jpg', '3.jpg', '1.jpg', '4.jpg'];
        $storedPaths = [];
        foreach ($completionOrder as $index => $file) {
            $filename = $completionOrderFilenames[$index];
            $path = $file->storeAs('images', $filename, 'public');
            $storedPaths[] = $path;
        }
        
        $user->update(['images' => $storedPaths]);
        $user->refresh();
        
        $retrievedImages = $user->images;
        
        // Verify that order does NOT match selection order (race condition detected)
        $firstFile = basename($retrievedImages[0]);
        $this->assertNotEquals(
            '1.jpg',
            $firstFile,
            'Race condition detected: First file should be 1.jpg (selection order) but is ' . $firstFile . ' (completion order)'
        );
        
        // Verify smaller files appear before larger files (race condition pattern)
        $this->assertStringContainsString('2.jpg', $retrievedImages[0], 'Smaller files should appear first in race condition');
    }

    /**
     * Test that file order is preserved when updating existing images.
     */
    public function test_file_order_preserved_on_update(): void
    {
        $user = User::where('email', 'test@example.com')->first();
        
        Storage::fake('public');
        
        // Initial upload
        $initialFiles = [
            UploadedFile::fake()->image('1.jpg'),
            UploadedFile::fake()->image('2.jpg'),
        ];
        
        $initialFilenames = ['1.jpg', '2.jpg'];
        $initialPaths = [];
        foreach ($initialFiles as $index => $file) {
            $filename = $initialFilenames[$index];
            $initialPaths[] = $file->storeAs('images', $filename, 'public');
        }
        
        $user->update(['images' => $initialPaths]);
        $user->refresh();
        
        // Add more files (should maintain order)
        $additionalFiles = [
            UploadedFile::fake()->image('3.jpg'),
            UploadedFile::fake()->image('4.jpg'),
        ];
        
        $additionalFilenames = ['3.jpg', '4.jpg'];
        $additionalPaths = [];
        foreach ($additionalFiles as $index => $file) {
            $filename = $additionalFilenames[$index];
            $additionalPaths[] = $file->storeAs('images', $filename, 'public');
        }
        
        $allPaths = array_merge($initialPaths, $additionalPaths);
        $user->update(['images' => $allPaths]);
        $user->refresh();
        
        // Verify order is maintained
        $this->assertCount(4, $user->images);
        $this->assertStringContainsString('1.jpg', $user->images[0]);
        $this->assertStringContainsString('2.jpg', $user->images[1]);
        $this->assertStringContainsString('3.jpg', $user->images[2]);
        $this->assertStringContainsString('4.jpg', $user->images[3]);
    }

    /**
     * Test edge case: single file upload maintains order.
     */
    public function test_single_file_upload(): void
    {
        $user = User::where('email', 'test@example.com')->first();
        
        Storage::fake('public');
        
        $file = UploadedFile::fake()->image('single.jpg');
        $path = $file->storeAs('images', 'single.jpg', 'public');
        
        $user->update(['images' => [$path]]);
        $user->refresh();
        
        $this->assertCount(1, $user->images);
        $this->assertStringContainsString('single.jpg', $user->images[0]);
    }

    /**
     * Test edge case: empty images array.
     */
    public function test_empty_images_array(): void
    {
        $user = User::where('email', 'test@example.com')->first();
        
        $user->update(['images' => []]);
        $user->refresh();
        
        $this->assertIsArray($user->images);
        $this->assertEmpty($user->images);
    }
}

