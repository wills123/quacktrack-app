<?php

/**
 * Allowed values of the deployment, quest and shipment ENUM columns. Kept free of any other
 * code so the services, the input schemas and the tests can all load them without the app bootstrap.
 */

declare(strict_types=1);

const DEPLOYMENT_STAGE_VALUES = ['planned', 'dark_week', 'in_progress', 'waiting_vendor', 'complete'];
const DEPLOYMENT_TASK_STATUS_VALUES = ['not_started', 'in_progress', 'complete'];
const DEPLOYMENT_QUEST_STATUS_VALUES = ['available', 'in_progress', 'blocked', 'ready_review', 'complete', 'archived'];
const DEPLOYMENT_QUEST_OBJECTIVE_STATUS_VALUES = ['not_started', 'in_progress', 'done'];
const DEPLOYMENT_QUEST_LOG_TYPES = ['update', 'blocker', 'resolution', 'handoff', 'completion', 'comment'];

const DEPLOYMENT_SHIPMENT_STATUSES = [
    'pending',
    'preparing',
    'dispatching',
    'dispatched',
    'partially_received',
    'received',
    'declined',
    'cancelled',
];
