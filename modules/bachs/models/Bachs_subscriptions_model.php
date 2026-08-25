<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * CRUD for tblbachs_subscriptions -- the Bachs-side fork of Perfex's native
 * tblsubscriptions. Deliberately mirrors Subscriptions_model's method shapes
 * (get / get_by_id / get_by_hash / create / update / delete) so the pair reads
 * as the same pattern, but carries none of the Stripe coupling: the native
 * model's handleSelectedTax() reaches straight into stripe_core even just to
 * resolve a tax rate, which is exactly why this had to be a fork rather than
 * a shared base.
 */
class Bachs_subscriptions_model extends App_Model
{
    public function __construct()
    {
        parent::__construct();
    }

    public function get($where = [])
    {
        $this->select();
        $this->join();
        $this->db->where($where);
        $this->db->order_by(db_prefix() . 'bachs_subscriptions.id', 'DESC');

        return $this->db->get(db_prefix() . 'bachs_subscriptions')->result();
    }

    public function get_by_id($id, $where = [])
    {
        $this->select();
        $this->join();
        $this->db->where(db_prefix() . 'bachs_subscriptions.id', $id);
        $this->db->where($where);

        return $this->db->get(db_prefix() . 'bachs_subscriptions')->row();
    }

    public function get_by_hash($hash, $where = [])
    {
        $this->select();
        $this->join();
        $this->db->where(db_prefix() . 'bachs_subscriptions.hash', $hash);
        $this->db->where($where);

        return $this->db->get(db_prefix() . 'bachs_subscriptions')->row();
    }

    public function get_by_bachs_id($bachsSubscriptionId)
    {
        if (empty($bachsSubscriptionId)) {
            return null;
        }

        $this->select();
        $this->join();
        $this->db->where(db_prefix() . 'bachs_subscriptions.bachs_subscription_id', $bachsSubscriptionId);

        return $this->db->get(db_prefix() . 'bachs_subscriptions')->row();
    }

    /**
     * Fallback link for a webhook whose metadata didn't survive the round
     * trip through checkout: the newest still-unsubscribed local record for
     * the same Bachs product. Only ever consulted after an id/hash match has
     * already failed.
     */
    public function get_pending_by_product($bachsProductId)
    {
        if (empty($bachsProductId)) {
            return null;
        }

        $this->select();
        $this->join();
        $this->db->where(db_prefix() . 'bachs_subscriptions.bachs_product_id', $bachsProductId);
        $this->db->where(db_prefix() . 'bachs_subscriptions.bachs_subscription_id IS NULL', null, false);
        $this->db->order_by(db_prefix() . 'bachs_subscriptions.id', 'DESC');
        $this->db->limit(1);

        return $this->db->get(db_prefix() . 'bachs_subscriptions')->row();
    }

    public function create($data)
    {
        $this->db->insert(db_prefix() . 'bachs_subscriptions', array_merge($data, [
            'created'      => date('Y-m-d H:i:s'),
            'hash'         => app_generate_hash(),
            'created_from' => get_staff_user_id(),
        ]));

        return $this->db->insert_id();
    }

    public function update($id, $data)
    {
        $this->db->where(db_prefix() . 'bachs_subscriptions.id', $id);
        $this->db->update(db_prefix() . 'bachs_subscriptions', $data);

        return $this->db->affected_rows() > 0;
    }

    /**
     * Refuses to delete a record still attached to a live Bachs subscription
     * unless explicitly forced -- same guard the native model applies, and for
     * the same reason: deleting the local row does not stop Bachs billing the
     * customer, it only loses our record that it is happening.
     */
    public function delete($id, $simpleDelete = false)
    {
        $subscription = $this->get_by_id($id);

        if (!$subscription) {
            return false;
        }

        if (!empty($subscription->bachs_subscription_id)
            && $subscription->status !== 'canceled'
            && $simpleDelete == false) {
            return false;
        }

        $this->db->where('id', $id);
        $this->db->delete(db_prefix() . 'bachs_subscriptions');

        return $this->db->affected_rows() > 0 ? $subscription : false;
    }

    private function select()
    {
        $this->db->select(
            db_prefix() . 'bachs_subscriptions.*, '
            . db_prefix() . 'clients.company as company'
        );
    }

    private function join()
    {
        $this->db->join(
            db_prefix() . 'clients',
            db_prefix() . 'clients.userid=' . db_prefix() . 'bachs_subscriptions.clientid',
            'left'
        );
    }
}
