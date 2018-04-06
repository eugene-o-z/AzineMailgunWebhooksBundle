<?php

namespace Azine\MailgunWebhooksBundle\Entity\Repositories;

use Azine\MailgunWebhooksBundle\Entity\MailgunEvent;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;

/**
 * MailgunEventRepository.
 *
 * This class was generated by the Doctrine ORM. Add your own custom
 * repository methods below.
 */
class MailgunEventRepository extends EntityRepository
{
    public function getEventCount($criteria)
    {
        $result = $this->getEventsQuery($criteria)->getQuery()->execute();

        return sizeof($result);
    }

    /**
     * Get MailgunEvent that match the search criteria.
     *
     * @param $criteria
     * @param $orderBy
     * @param $limit
     * @param $offset
     *
     * @return mixed
     */
    public function getEvents($criteria, $orderBy, $limit, $offset)
    {
        $qb = $this->getEventsQuery($criteria);
        $orderField = key($orderBy);
        $orderDirection = $orderBy[$orderField];
        $qb->orderBy('e.'.$orderField, $orderDirection);
        if ($limit != -1) {
            $qb->setMaxResults($limit);
            $qb->setFirstResult($offset);
        }

        $result = $qb->getQuery()->execute();

        return $result;
    }

    /**
     * @param array $criteria
     *
     * @return QueryBuilder
     */
    private function getEventsQuery($criteria)
    {
        $lookForUnopened = array_key_exists('eventType', $criteria) && 'unopened' == $criteria['eventType'];
        if ($lookForUnopened) {
            unset($criteria['eventType']);
        }

        $qb = $this->createQueryBuilder('e');
        if (array_key_exists('domain', $criteria) && '' != $criteria['domain']) {
            $qb->andWhere('e.domain = :domain')
                ->setParameter('domain', $criteria['domain']);
        }

        if (array_key_exists('recipient', $criteria) && '' != $criteria['recipient']) {
            $qb->andWhere('e.recipient like :recipient')
                ->setParameter('recipient', '%'.$criteria['recipient'].'%');
        }

        if (array_key_exists('eventType', $criteria) && 'all' != $criteria['eventType']) {
            $qb->andWhere('e.event = :eventType')
                ->setParameter('eventType', $criteria['eventType']);
        }

        if (array_key_exists('search', $criteria) && '' != $criteria['search']) {
            $qb->andWhere('(e.messageHeaders like :search OR e.description like :search OR e.notification like :search OR e.reason like :search OR e.errorCode like :search OR e.ip like :search OR e.error like :search OR e.country like :search OR e.city like :search OR e.campaignId like :search OR e.campaignName like :search OR e.clientName like :search OR e.clientOs like :search OR e.clientType like :search OR e.deviceType like :search OR e.mailingList like :search OR e.messageId like :search OR e.tag like :search OR e.userAgent like :search OR e.url like :search)')
                ->setParameter('search', '%'.$criteria['search'].'%');
        }

        if ($lookForUnopened) {
            $qb->andWhere("NOT EXISTS (SELECT o.id FROM AzineMailgunWebhooksBundle:MailgunEvent o WHERE o.messageId like e.messageId AND o.event in ('opened', 'clicked', 'unsubscribed', 'complained'))");
        }

        return $qb;
    }

    /**
     * Get distinct list of types of events.
     *
     * @return array of string
     */
    public function getEventTypes()
    {
        $q = $this->getEntityManager()->createQueryBuilder()
            ->select('e.event as event')
            ->from($this->getEntityName(), 'e')
            ->distinct()
            ->orderBy('e.event ', 'asc')
            ->getQuery();
        $result = array();
        foreach ($q->execute() as $next) {
            $result[] = $next['event'];
        }

        return $result;
    }

    /**
     * Get distinct list of email domains.
     *
     * @return array of string
     */
    public function getDomains()
    {
        $q = $this->getEntityManager()->createQueryBuilder()
            ->select('e.domain as domain')
            ->from($this->getEntityName(), 'e')
            ->distinct()
            ->orderBy('e.domain ', 'asc')
            ->getQuery();

        $result = array();
        foreach ($q->execute() as $next) {
            $result[] = $next['domain'];
        }

        return $result;
    }

    /**
     * Get distinct list of email recipients.
     *
     * @return array of string
     */
    public function getRecipients()
    {
        $q = $this->getEntityManager()->createQueryBuilder()
            ->select('e.recipient as recipient')
            ->from($this->getEntityName(), 'e')
            ->distinct()
            ->orderBy('e.recipient ', 'asc')
            ->getQuery();

        $result = array();
        foreach ($q->execute() as $next) {
            $result[] = $next['recipient'];
        }

        return $result;
    }

    /**
     * Get last known sender IP based on stored events list.
     *
     * @return string|null
     */
    public function getLastKnownSenderIp()
    {
        $q = $this->getEntityManager()->createQueryBuilder()
            ->select('e.messageHeaders as mh')
            ->from($this->getEntityName(), 'e')
            ->orderBy('e.timestamp', 'desc')
            ->where('e.event IN (:events)')
            ->setParameter('events', array('opened', 'delivered', 'bounced', 'dropped', 'complained'))
            ->setMaxResults(50)
            ->getQuery();

        foreach ($q->execute() as $next) {
            if ($next['mh']) {
                foreach ($next['mh'] as $nextHeader) {
                    if ('X-Mailgun-Sending-Ip' == $nextHeader[0]) {
                        return $nextHeader[1];
                    }
                }
            }
        }

        return null;
    }

    /**
     * Get a list of event fields that can be used for ordering results.
     *
     * @return array
     */
    public function getFieldsToOrderBy()
    {
        return array(
                'campaignId',
                'campaignName',
                'city',
                'clientName',
                'clientOs',
                'clientType',
                'country',
                'description',
                'deviceType',
                'domain',
                'error',
                'errorCode',
                'event',
                'ip',
                'mailingList',
                'messageHeaders',
                'messageId',
                'notification',
                'reason',
                'recipient',
                'region',
                'tag',
                'timestamp',
                'type',
                'userAgent',
                'url',
            );
    }

    /**
     * Get the most important events in the following priority
     * 1. Errors (rejected, failed)
     * 2. Warnings (complained, unsubscribed)
     * 3. Infos (accepted, delivered, opened, clicked, stored).
     *
     * @param int $count max number of events to fetch
     *
     * @return array of MailgunEvent
     */
    public function getImportantEvents($count)
    {
        $errors = $this->getEventsBySeverity(MailgunEvent::SEVERITY_ERROR, $count);
        $results = $errors;

        $getMoreCounter = $count - sizeof($errors);

        if ($getMoreCounter > 0) {
            $warnings = $this->getEventsBySeverity(MailgunEvent::SEVERITY_WARN, $count);
            $getMoreCounter = $getMoreCounter - sizeof($warnings);
            $results = array_merge($results, $warnings);
        }

        if ($getMoreCounter > 0) {
            $infos = $this->getEventsBySeverity(MailgunEvent::SEVERITY_INFO, $count);
            $results = array_merge($results, $infos);
        }

        return $results;
    }

    /**
     * Get events by severity/type.
     *
     * @param string $severity [info, warning, error]
     *
     * @return array of MailgunEvent
     */
    public function getEventsBySeverity($severity = MailgunEvent::SEVERITY_INFO, $maxResults = 0)
    {
        if (MailgunEvent::SEVERITY_INFO == $severity) {
            $criteria = "e.event = 'accepted' or e.event = 'delivered' or e.event = 'opened' or e.event = 'clicked' or e.event = 'stored'";
        } elseif (MailgunEvent::SEVERITY_WARN == $severity) {
            $criteria = "e.event = 'complained' or e.event = 'unsubscribed'";
        } elseif (MailgunEvent::SEVERITY_ERROR == $severity) {
            $criteria = "e.event = 'rejected' or e.event = 'failed' or e.event = 'dropped' or e.event = 'bounced'";
        } else {
            return null;
        }

        $qb = $this->createQueryBuilder('e')
            ->where($criteria)
            ->orderBy('e.timestamp ', 'desc');

        if ($maxResults > 0) {
            $qb->setMaxResults($maxResults);
        }

        $q = $qb->getQuery();
        $results = $q->execute();

        return $results;
    }
}
