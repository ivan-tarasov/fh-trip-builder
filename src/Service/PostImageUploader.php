<?php

declare(strict_types=1);

namespace TripBuilder\Service;

use RuntimeException;
use TripBuilder\Aws\ObjectStore;
use TripBuilder\Aws\S3;
use TripBuilder\Config;
use TripBuilder\View\Airside\PostImageSet;

/**
 * One original in, every copy the pages ask for out.
 *
 * The names come from `PostImageSet`, which the templates already read, so
 * there is one description of what exists and both ends use it. A second list
 * here would be the kind that drifts by one entry and shows as a broken image
 * on the size nobody tests at.
 */
final readonly class PostImageUploader
{
    public function __construct(
        private ObjectStore $s3,
        private ImageResizer $resizer,
    ) {}

    public static function fromEnvironment(): self
    {
        return new self(S3::fromEnvironment(), new ImageResizer());
    }

    /**
     * Upload whatever is not already there, and say what was sent.
     *
     * Nothing is overwritten, because nothing needs to be: the name carries a
     * hash of the bytes, so a key that exists holds exactly these bytes
     * already. That is what makes a re-import cost one HEAD per variant
     * instead of seven PUTs.
     *
     * @return list<string> the keys uploaded, in the order they were sent
     */
    public function upload(string $canonical, string $contents): array
    {
        $mime = self::mimeOf($contents);
        $sent = [];

        // The un-suffixed name first, holding the original. The templates use
        // it as the `src` beside the `srcset`, so it has to resolve -- and
        // since the staging directory is not committed, this is also the only
        // surviving copy of what the author actually supplied.
        foreach ([['name' => $canonical, 'size' => null, 'square' => false], ...PostImageSet::all($canonical)] as $variant) {
            $key = self::prefix() . '/' . $variant['name'];

            if ($this->s3->has($key)) {
                continue;
            }

            $this->s3->put($key, match (true) {
                $variant['size'] === null => $contents,
                $variant['square'] => $this->resizer->toSquare($contents, $variant['size']),
                default => $this->resizer->toWidth($contents, $variant['size']),
            }, $mime);

            $sent[] = $key;
        }

        return $sent;
    }

    /**
     * One file under its own name, for the images a body carries.
     *
     * No variants and no hash, because the renderer asks for these by the name
     * the author typed -- `![](wing.jpg)` becomes `.../airside/wing.jpg`. A
     * hash here would have to be written back into the markdown, which is the
     * author's file and not this command's to edit.
     *
     * @return string|null the key, or null where it was already there
     */
    public function uploadOne(string $name, string $contents): ?string
    {
        $key = self::prefix() . '/' . $name;

        if ($this->s3->has($key)) {
            return null;
        }

        $this->s3->put($key, $contents, self::mimeOf($contents));

        return $key;
    }

    /** Where these live in the bucket, spelled the same way the pages read it. */
    public static function prefix(): string
    {
        return Config::get('site.static.endpoint.images', 'images') . '/airside';
    }

    private static function mimeOf(string $contents): string
    {
        $size = getimagesizefromstring($contents);

        if ($size === false) {
            throw new RuntimeException('That is not an image this can read.');
        }

        return (string) $size['mime'];
    }
}
