<?php

namespace Atannex\Foundation;

use Atannex\Foundation\Concerns\CanGenerateCode;
use Atannex\Foundation\Concerns\CanGenerateSlug;
use Atannex\Foundation\Concerns\CanGenerateSlugPath;
use Atannex\Foundation\Concerns\HandleFileCleaning;
use Atannex\Foundation\Concerns\HandlesDateArchiveResolution;
use Atannex\Foundation\Concerns\HasReadingTime;
// use Atannex\Foundation\Concerns\ResolvesDynamicContent;

class FoundationManager {

    use CanGenerateCode;
    use CanGenerateSlugPath;
    use CanGenerateSlug;
    use HandleFileCleaning;
    use HandlesDateArchiveResolution;
    use HasReadingTime;
    // use ResolvesDynamicContent;
}
