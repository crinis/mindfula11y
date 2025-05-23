<?php

declare(strict_types=1);

namespace MindfulMarkup\MindfulA11y\Enum;

/**
 * FileTable enum.
 * 
 * Enum containing name of files tables. This is used to avoid hardcoding the table names.
 */
enum FileTable: string
{
    case FILE = 'sys_file';
    case FILE_REFERENCE = 'sys_file_reference';
}
