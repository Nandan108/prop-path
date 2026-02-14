<?php

namespace Nandan108\PropPath\Exception;

enum EvaluationErrorCode: string
{
    case UNKNOWN = 'proppath.eval.failure';

    case CONTAINER_EXPECTED = 'proppath.eval.container_expected';
    case FLATTEN_EMPTY = 'proppath.eval.flatten_empty';
    case INVALID_KEY_TYPE = 'proppath.eval.invalid_key_type';

    case REGEXP_INVALID_SUBJECT = 'proppath.eval.regexp.invalid_subject';
    case REGEXP_EMPTY_SUBJECT = 'proppath.eval.regexp.empty_subject';
    case REGEXP_NO_MATCH = 'proppath.eval.regexp.no_match';

    case ONEACH_NON_ITERABLE = 'proppath.eval.on_each.non_iterable';
    case ONEACH_EMPTY = 'proppath.eval.on_each.empty';
    case ONEACH_NO_RESULTS = 'proppath.eval.on_each.no_results';

    case ROOT_NOT_FOUND = 'proppath.eval.root_not_found';

    case KEY_NOT_FOUND_ARRAY = 'proppath.eval.key.not_found_array';
    case KEY_NOT_FOUND_ARRAY_ACCESS = 'proppath.eval.key.not_found_array_access';
    case KEY_NOT_ACCESSIBLE_OBJECT = 'proppath.eval.key.not_accessible_object';
    case KEY_NON_CONTAINER = 'proppath.eval.key.non_container';
    case NULL_VALUE_REQUIRED = 'proppath.eval.null_value_required';

    case SLICE_NULL_SUBJECT = 'proppath.eval.slice.null_subject';
    case SLICE_INVALID_SUBJECT = 'proppath.eval.slice.invalid_subject';
    case SLICE_NON_COUNTABLE_START = 'proppath.eval.slice.non_countable_start';
    case SLICE_NON_COUNTABLE_END = 'proppath.eval.slice.non_countable_end';
    case SLICE_MISSING_KEYS = 'proppath.eval.slice.missing_keys';
    case SLICE_CONTAINS_NULL = 'proppath.eval.slice.contains_null';

    case BRACKET_ON_NULL = 'proppath.eval.bracket_on_null';
    case STACK_REF_OUT_OF_BOUNDS = 'proppath.eval.stack_ref.out_of_bounds';
}
