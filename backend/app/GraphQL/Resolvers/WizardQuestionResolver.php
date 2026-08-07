<?php

namespace App\GraphQL\Resolvers;

use App\Models\TaxonomyNode;
use App\Models\WizardQuestion;

class WizardQuestionResolver
{
    /**
     * Return the taxonomy choices available for a wizard question.
     * Optionally scoped to a parent node (e.g. cities under a chosen country).
     */
    public function options(WizardQuestion $question, array $args)
    {
        if (! $question->taxonomy_type) {
            return [];
        }

        $query = TaxonomyNode::query()
            ->where('type', $question->taxonomy_type)
            ->orderBy('sort_order');

        if (! empty($args['parentId'])) {
            $query->where('parent_id', $args['parentId']);
        }

        return $query->get();
    }
}
