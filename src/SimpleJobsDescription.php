<?php

namespace LeKoala\SimpleJobs;

use SilverStripe\ORM\DataList;

interface SimpleJobsDescription
{
    public const SYSTEM = "system";

    public static function getJobTitle(): string;
    public static function getJobCategory(): string;
    public static function getJobDescription(): string;
    public static function getJobRecords(): ?DataList;
}
